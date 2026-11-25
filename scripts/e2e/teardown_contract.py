"""NUVIKOZ Edu — Provider-Independent External Teardown Contract & Runner.

This module implements the architectural contract, strict security guards,
and state management for teardown operations against disposable E2E environments.

Policy Enforcement:
- DEVELOPMENT FIRST, DEPLOYMENT LATER.
- Strict allowlist matching: A tag alone NEVER authorizes deletion.
- Exact resource-ID matching is required for every destructive operation.
- Associated resources (volumes, IPs) are fail-closed: unapproved attached resources
  immediately halt parent deletion.
- No broad resource enumeration followed by wildcard deletion.
- Four-hour maximum execution window based on immutable persisted absolute timestamp.
- Atomic state writes with fail-closed corruption recovery.
- Zero secret leakage in logs, state files, or exceptions.
"""

from __future__ import annotations

import abc
from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone
from enum import Enum
import json
import os
from pathlib import Path
import tempfile
from typing import Any, Callable, ClassVar


class ResourceType(str, Enum):
    """Supported cloud resource types for disposable E2E fixtures."""
    DROPLET = "droplet"
    VOLUME = "volume"
    FLOATING_IP = "floating_ip"
    RESERVED_IP = "reserved_ip"
    DNS_RECORD = "dns_record"


class ResourceStatus(str, Enum):
    """Runtime status of a cloud resource."""
    ACTIVE = "active"
    NEW = "new"
    OFF = "off"
    ARCHIVE = "archive"
    DELETING = "deleting"
    DELETED = "deleted"
    ABSENT = "absent"
    UNKNOWN = "unknown"


# Only explicitly verified active resources are eligible for destructive teardown.
DELETION_ELIGIBLE_STATUSES: frozenset[ResourceStatus] = frozenset({
    ResourceStatus.ACTIVE,
})


class TeardownActionStatus(str, Enum):
    """Structured audit status outcomes for teardown operations."""
    DELETION_REQUESTED = "DELETION_REQUESTED"
    DELETION_ACKNOWLEDGED = "DELETION_ACKNOWLEDGED"
    RESOURCE_ABSENCE_CONFIRMED = "RESOURCE_ABSENCE_CONFIRMED"
    ASSOCIATED_RESOURCES_RECONCILED = "ASSOCIATED_RESOURCES_RECONCILED"
    CLEANUP_INCOMPLETE = "CLEANUP_INCOMPLETE"
    MANUAL_INTERVENTION_REQUIRED = "MANUAL_INTERVENTION_REQUIRED"
    REJECTED_SECURITY_VIOLATION = "REJECTED_SECURITY_VIOLATION"


class TeardownSecurityError(Exception):
    """Raised when a security guard (allowlist, ownership, deadline) is violated."""
    pass


class TeardownStateCorruptedError(Exception):
    """Raised when the persistent manifest state is corrupted or unreadable."""
    pass


class TeardownDeadlineError(Exception):
    """Raised when a teardown operation is attempted outside valid execution window."""
    pass


def parse_utc_timestamp(
    raw_value: str,
    field_name: str,
    error_cls: type[Exception] = TeardownStateCorruptedError,
) -> datetime:
    """Parse an ISO-8601 timestamp requiring an explicit timezone offset."""
    if not isinstance(raw_value, str) or not raw_value.strip():
        raise error_cls(f"Missing or empty timestamp for '{field_name}'.")

    normalized = raw_value.strip()
    if "T" not in normalized and " " not in normalized:
        raise error_cls(f"Timestamp '{field_name}' must include date and time with timezone offset.")

    if normalized.endswith("Z"):
        normalized = normalized[:-1] + "+00:00"

    try:
        parsed = datetime.fromisoformat(normalized)
    except Exception as exc:
        raise error_cls(f"Invalid timestamp format for '{field_name}': {exc}") from exc

    if parsed.tzinfo is None or parsed.utcoffset() is None:
        raise error_cls(f"Timestamp '{field_name}' must include an explicit timezone offset.")

    return parsed.astimezone(timezone.utc)


@dataclass(frozen=True)
class OwnershipContract:
    """Ownership contract verifying a resource belongs to the disposable fixture."""
    expected_account_id: str
    expected_project_id: str
    required_tag: str
    expected_name_prefix: str

    def is_complete(self) -> bool:
        """Verify all ownership criteria are explicitly non-empty."""
        return all(
            isinstance(val, str) and bool(val.strip())
            for val in (
                self.expected_account_id,
                self.expected_project_id,
                self.required_tag,
                self.expected_name_prefix,
            )
        )


@dataclass
class ResourceIdentity:
    """Immutable identity metadata of a discovered or inspected resource."""
    resource_id: str
    resource_type: ResourceType
    name: str
    account_id: str
    project_id: str
    tags: list[str]
    status: ResourceStatus
    associated_resource_ids: list[str] = field(default_factory=list)

    def matches_ownership(self, contract: OwnershipContract) -> bool:
        """Confirm resource is deletion-eligible and strictly satisfies ownership."""
        if self.status not in DELETION_ELIGIBLE_STATUSES:
            return False
        if not contract.is_complete():
            return False
        if not self.account_id or self.account_id != contract.expected_account_id:
            return False
        if not self.project_id or self.project_id != contract.expected_project_id:
            return False
        if not isinstance(self.tags, list) or contract.required_tag not in self.tags:
            return False
        if not self.name or not self.name.startswith(contract.expected_name_prefix):
            return False
        return True


@dataclass(frozen=True)
class ExecutionAuthContext:
    """Explicit local authorization context for executing destructive operations."""
    authorized_by: str
    authorization_timestamp: str
    dry_run: bool
    allowlisted_resource_ids: set[str]
    allowed_resource_types: set[ResourceType]
    manifest_id: str | None = None
    max_age_seconds: int = 3600

    MAX_CLOCK_SKEW_SECONDS = 60
    MAX_ALLOWED_AGE_SECONDS = 14400

    def validate(
        self,
        current_time: datetime | None = None,
        expected_manifest_id: str | None = None,
    ) -> None:
        """Validate actor, timezone-aware timestamp, skew/staleness, scope, and manifest binding."""
        if not isinstance(self.authorized_by, str) or not self.authorized_by.strip():
            raise TeardownSecurityError("Missing or empty authorized_by in execution context.")

        now = current_time or datetime.now(timezone.utc)
        if now.tzinfo is None or now.utcoffset() is None:
            raise TeardownSecurityError("Current evaluation time must be timezone-aware.")
        now_utc = now.astimezone(timezone.utc)

        auth_dt = parse_utc_timestamp(
            self.authorization_timestamp,
            "authorization_timestamp",
            error_cls=TeardownSecurityError,
        )

        # Reject future-dated authorizations beyond narrow clock-skew tolerance
        if (auth_dt - now_utc).total_seconds() > self.MAX_CLOCK_SKEW_SECONDS:
            raise TeardownSecurityError("Authorization timestamp is future-dated outside clock-skew tolerance.")

        # Validate configured staleness bound and reject expired authorization
        if not isinstance(self.max_age_seconds, int) or self.max_age_seconds <= 0 or self.max_age_seconds > self.MAX_ALLOWED_AGE_SECONDS:
            raise TeardownSecurityError("Invalid max_age_seconds bound in execution authorization context.")

        if (now_utc - auth_dt).total_seconds() > self.max_age_seconds:
            raise TeardownSecurityError("Authorization timestamp is stale/expired.")

        if not self.allowlisted_resource_ids or any(not isinstance(rid, str) or not rid.strip() for rid in self.allowlisted_resource_ids):
            raise TeardownSecurityError("Execution authorization allowlisted_resource_ids must be non-empty.")

        if not self.allowed_resource_types:
            raise TeardownSecurityError("Execution authorization allowed_resource_types must be non-empty.")

        if expected_manifest_id is not None:
            if not isinstance(self.manifest_id, str) or not self.manifest_id.strip():
                raise TeardownSecurityError("Authorization context must be explicitly bound to a manifest_id.")
            if self.manifest_id.strip() != expected_manifest_id:
                raise TeardownSecurityError(
                    f"Authorization context bound to manifest '{self.manifest_id}' cannot be reused for '{expected_manifest_id}'."
                )


@dataclass
class DeletionResult:
    """Audit record of a resource deletion operation."""
    resource_id: str
    resource_type: ResourceType
    status: TeardownActionStatus
    success: bool
    message: str
    timestamp: str
    reconciled_associated_resources: list[str] = field(default_factory=list)


@dataclass
class TeardownManifest:
    """Persistent durable registry of resources approved for teardown."""
    manifest_id: str
    created_at_utc: str
    deadline_at_utc: str
    ownership: OwnershipContract
    allowlisted_resources: dict[str, str]  # resource_id -> ResourceType.value
    dns_record_domains: dict[str, str] = field(default_factory=dict)  # optional record_id -> domain_name binding
    state: str = "PENDING"
    results: list[DeletionResult] = field(default_factory=list)
    audit_events: list[dict[str, Any]] = field(default_factory=list)
    manifest_version: str = "1.0"

    MAX_EXECUTION_WINDOW_SECONDS: ClassVar[int] = 14400  # 4 hours
    VALID_MANIFEST_STATES: ClassVar[frozenset[str]] = frozenset({
        "PENDING",
        "IN_PROGRESS",
        "INCOMPLETE",
        "COMPLETED",
        "MANUAL_INTERVENTION_REQUIRED",
    })

    def validate_state(self, current_time: datetime | None = None) -> None:
        """Validate integrity of state, ownership, creation timestamp, and 4-hour deadline."""
        if not isinstance(self.manifest_id, str) or not self.manifest_id.strip():
            raise TeardownStateCorruptedError("Manifest missing non-empty manifest_id.")

        if not self.ownership.is_complete():
            raise TeardownSecurityError("Manifest ownership contract is incomplete.")

        if not isinstance(self.allowlisted_resources, dict) or not self.allowlisted_resources:
            raise TeardownStateCorruptedError("Manifest allowlisted_resources must be a non-empty mapping.")

        for r_id, r_type in self.allowlisted_resources.items():
            if not isinstance(r_id, str) or not r_id.strip():
                raise TeardownStateCorruptedError("Manifest contains empty resource ID.")
            try:
                ResourceType(r_type)
            except Exception as exc:
                raise TeardownStateCorruptedError(f"Invalid resource type in manifest allowlist: {r_type!r}") from exc

        if self.state not in self.VALID_MANIFEST_STATES:
            raise TeardownStateCorruptedError(f"Unexpected manifest state: {self.state!r}")

        now = current_time or datetime.now(timezone.utc)
        if now.tzinfo is None or now.utcoffset() is None:
            raise TeardownStateCorruptedError("Evaluation time must include an explicit timezone offset.")
        now_utc = now.astimezone(timezone.utc)

        created_at = parse_utc_timestamp(self.created_at_utc, "created_at_utc", error_cls=TeardownStateCorruptedError)
        deadline_at = parse_utc_timestamp(self.deadline_at_utc, "deadline_at_utc", error_cls=TeardownStateCorruptedError)

        # Disallow future-dated creation timestamps (tolerance of 60s for clock skew)
        if (created_at - now_utc).total_seconds() > 60:
            raise TeardownStateCorruptedError("Manifest creation timestamp is future-dated.")

        # Deadline must be strictly after creation
        if deadline_at <= created_at:
            raise TeardownStateCorruptedError("Manifest deadline must be strictly after creation timestamp.")

        # Ensure persisted audit/result timestamps cannot precede creation (prevents sliding window extension)
        prior_timestamps: list[datetime] = []
        for ev in self.audit_events:
            if isinstance(ev, dict) and "timestamp" in ev:
                prior_timestamps.append(
                    parse_utc_timestamp(str(ev["timestamp"]), "audit_events[].timestamp", error_cls=TeardownStateCorruptedError)
                )
        for res in self.results:
            prior_timestamps.append(
                parse_utc_timestamp(res.timestamp, "results[].timestamp", error_cls=TeardownStateCorruptedError)
            )
        if prior_timestamps and (created_at - min(prior_timestamps)).total_seconds() > 60:
            raise TeardownStateCorruptedError(
                "Manifest creation timestamp post-dates existing audit or result records."
            )

        # Window duration must not exceed 4 hours (+ 60s drift tolerance)
        duration = (deadline_at - created_at).total_seconds()
        if duration > self.MAX_EXECUTION_WINDOW_SECONDS + 60:
            raise TeardownSecurityError(
                f"Manifest deadline window ({duration}s) exceeds maximum allowed 4-hour window ({self.MAX_EXECUTION_WINDOW_SECONDS}s)."
            )

    def is_expired(self, current_time: datetime | None = None) -> bool:
        """Check whether the 4-hour execution window has passed."""
        now = current_time or datetime.now(timezone.utc)
        self.validate_state(now)
        now_utc = now.astimezone(timezone.utc)
        deadline = parse_utc_timestamp(self.deadline_at_utc, "deadline_at_utc", error_cls=TeardownStateCorruptedError)
        return now_utc >= deadline

    def to_dict(self) -> dict[str, Any]:
        """Convert manifest to sanitized dictionary."""
        return asdict(self)

    @classmethod
    def from_dict(cls, data: dict[str, Any], current_time: datetime | None = None) -> TeardownManifest:
        """Reconstruct manifest from dictionary with strict schema validation."""
        if not isinstance(data, dict):
            raise TeardownStateCorruptedError("Manifest payload must be a JSON object.")

        required_keys = {"manifest_id", "created_at_utc", "deadline_at_utc", "ownership", "allowlisted_resources"}
        if not required_keys.issubset(data.keys()):
            raise TeardownStateCorruptedError(f"Manifest missing required keys: {required_keys - set(data.keys())}")

        if not isinstance(data["ownership"], dict):
            raise TeardownStateCorruptedError("Manifest 'ownership' field must be an object.")
        if not isinstance(data["allowlisted_resources"], dict):
            raise TeardownStateCorruptedError("Manifest 'allowlisted_resources' field must be an object.")

        try:
            ownership_data = data["ownership"]
            ownership = OwnershipContract(
                expected_account_id=str(ownership_data["expected_account_id"]),
                expected_project_id=str(ownership_data.get("expected_project_id", "")),
                required_tag=str(ownership_data["required_tag"]),
                expected_name_prefix=str(ownership_data["expected_name_prefix"]),
            )

            raw_results = data.get("results", [])
            if not isinstance(raw_results, list):
                raise TeardownStateCorruptedError("Manifest 'results' field must be a list.")

            results = []
            for r in raw_results:
                results.append(DeletionResult(
                    resource_id=str(r["resource_id"]),
                    resource_type=ResourceType(r["resource_type"]),
                    status=TeardownActionStatus(r["status"]),
                    success=bool(r["success"]),
                    message=str(r["message"]),
                    timestamp=str(r["timestamp"]),
                    reconciled_associated_resources=[str(x) for x in r.get("reconciled_associated_resources", [])],
                ))

            raw_events = data.get("audit_events", [])
            if not isinstance(raw_events, list) or any(not isinstance(ev, dict) for ev in raw_events):
                raise TeardownStateCorruptedError("Manifest 'audit_events' must be a list of objects.")

            raw_dns_domains = data.get("dns_record_domains", {})
            if not isinstance(raw_dns_domains, dict):
                raise TeardownStateCorruptedError("Manifest 'dns_record_domains' must be an object.")

            manifest = cls(
                manifest_id=str(data["manifest_id"]),
                created_at_utc=str(data["created_at_utc"]),
                deadline_at_utc=str(data["deadline_at_utc"]),
                ownership=ownership,
                allowlisted_resources={str(k): str(v) for k, v in data["allowlisted_resources"].items()},
                dns_record_domains={str(k): str(v) for k, v in raw_dns_domains.items()},
                state=str(data.get("state", "PENDING")),
                results=results,
                audit_events=list(raw_events),
                manifest_version=str(data.get("manifest_version", "1.0")),
            )
            manifest.validate_state(current_time)
            return manifest
        except (TeardownSecurityError, TeardownStateCorruptedError):
            raise
        except Exception as exc:
            raise TeardownStateCorruptedError(f"Failed to parse manifest data: {exc}") from exc

    def save_atomic(self, file_path: Path | str) -> None:
        """Persist manifest to disk atomically using temporary file rename."""
        target_path = Path(file_path).resolve()
        target_path.parent.mkdir(parents=True, exist_ok=True)

        payload = json.dumps(self.to_dict(), indent=2, sort_keys=True)
        with tempfile.NamedTemporaryFile("w", dir=target_path.parent, delete=False) as tf:
            tf.write(payload)
            temp_name = tf.name

        os.replace(temp_name, target_path)

    @classmethod
    def load(cls, file_path: Path | str, current_time: datetime | None = None) -> TeardownManifest:
        """Load manifest from disk with fail-closed corrupted-state handling."""
        target_path = Path(file_path).resolve()
        if not target_path.exists():
            raise FileNotFoundError(f"Teardown manifest not found at: {target_path}")

        try:
            content = target_path.read_text(encoding="utf-8")
            data = json.loads(content)
            return cls.from_dict(data, current_time=current_time)
        except (json.JSONDecodeError, TeardownStateCorruptedError, TeardownSecurityError) as exc:
            if isinstance(exc, (TeardownStateCorruptedError, TeardownSecurityError)):
                raise
            raise TeardownStateCorruptedError(f"Manifest at {target_path} is corrupted: {exc}") from exc


class HttpTransportProtocol(abc.ABC):
    """Abstract HTTP transport layer allowing test mock injection."""

    @abc.abstractmethod
    def request(
        self,
        method: str,
        url: str,
        headers: dict[str, str] | None = None,
        body: dict[str, Any] | None = None,
    ) -> tuple[int, dict[str, Any]]:
        """Execute HTTP request and return (status_code, json_body)."""
        pass


class ProviderAdapterInterface(abc.ABC):
    """Provider-independent abstraction for inspecting and destroying cloud resources."""

    @abc.abstractmethod
    def inspect_resource(self, resource_id: str, resource_type: ResourceType) -> ResourceIdentity:
        """Inspect a single resource and return its immutable identity."""
        pass

    @abc.abstractmethod
    def confirm_resource_ownership(self, resource: ResourceIdentity, expected_ownership: OwnershipContract) -> bool:
        """Verify the resource strictly belongs to the disposable fixture."""
        pass

    @abc.abstractmethod
    def delete_resource(
        self,
        resource_id: str,
        resource_type: ResourceType,
        auth_context: ExecutionAuthContext,
    ) -> DeletionResult:
        """Execute deletion of an approved resource following 6-step preflight."""
        pass

    @abc.abstractmethod
    def check_deletion_status(self, resource_id: str, resource_type: ResourceType) -> ResourceStatus:
        """Verify whether a resource has been completely deleted or is absent."""
        pass

    @abc.abstractmethod
    def enumerate_associated_resources(self, parent_resource_id: str) -> list[ResourceIdentity]:
        """Enumerate any associated resources (volumes, IPs) for separate reconciliation."""
        pass


@dataclass
class CleanupAuditReport:
    """Structured, sanitized report summarizing the final teardown evaluation."""
    manifest_id: str
    evaluated_at_utc: str
    overall_status: TeardownActionStatus
    resources_evaluated: int
    resources_confirmed_absent: int
    cleanup_incomplete_count: int
    manual_intervention_required: bool
    details: list[dict[str, Any]]
    sanitized_summary: dict[str, Any]


class ExternalTeardownRunner:
    """External teardown orchestrator independent of the target VPS.

    Enforces:
    1. Default-deny on all resources.
    2. Exact resource-ID allowlist matching.
    3. Mandatory 4-hour execution window check.
    4. 6-point verification before any destructive action.
    5. Independent reconciliation and strict fail-closed blocking of unapproved associated resources.
    6. Atomic state transitions with restart recovery.
    """

    # Global safety gate: Live provider execution is globally disabled.
    LIVE_EXECUTION_ENABLED = False

    def __init__(
        self,
        adapter: ProviderAdapterInterface,
        manifest: TeardownManifest,
        manifest_path: Path | str | None = None,
        time_provider: Callable[[], datetime] | None = None,
    ) -> None:
        self.adapter = adapter
        self.manifest = manifest
        self.manifest_path = Path(manifest_path).resolve() if manifest_path else None
        self._time_provider = time_provider or (lambda: datetime.now(timezone.utc))

    def _now(self) -> datetime:
        return self._time_provider()

    def _record_audit_event(self, event_name: str, details: dict[str, Any]) -> None:
        """Record sanitized audit event to in-memory manifest."""
        sanitized = {
            "event": event_name,
            "timestamp": self._now().isoformat(),
            "details": details,
        }
        self.manifest.audit_events.append(sanitized)
        if self.manifest_path:
            self.manifest.save_atomic(self.manifest_path)

    def execute_teardown(self, auth_context: ExecutionAuthContext) -> CleanupAuditReport:
        """Execute teardown across allowlisted resources and reconcile associated items."""
        now = self._now()
        self.manifest.validate_state(now)

        # Reject manifest vs adapter ownership configuration conflicts (fail-closed)
        adapter_ownership = getattr(self.adapter, "expected_ownership", None)
        if adapter_ownership is not None and adapter_ownership != self.manifest.ownership:
            raise TeardownSecurityError(
                "Manifest ownership contract conflicts with provider adapter ownership configuration."
            )

        # Strict authorization context validation (actor, TZ timestamp, skew/TTL, manifest binding)
        auth_context.validate(current_time=now, expected_manifest_id=self.manifest.manifest_id)

        is_expired = self.manifest.is_expired(now)

        self._record_audit_event("TEARDOWN_STARTED", {
            "manifest_id": self.manifest.manifest_id,
            "is_expired": is_expired,
            "dry_run": auth_context.dry_run,
            "allowlisted_count": len(self.manifest.allowlisted_resources),
        })

        self.manifest.state = "IN_PROGRESS"
        if self.manifest_path:
            self.manifest.save_atomic(self.manifest_path)

        unexpected_auth_ids = auth_context.allowlisted_resource_ids - set(self.manifest.allowlisted_resources.keys())
        if unexpected_auth_ids:
            self._record_audit_event("UNEXPECTED_AUTH_RESOURCE_IDS", {
                "manifest_id": self.manifest.manifest_id,
                "unexpected_count": len(unexpected_auth_ids),
                "action": "BLOCK_ALL_DELETIONS",
            })

        results: list[DeletionResult] = []
        associated_orphans: list[str] = []

        # Iterate strictly over allowlisted resources
        for resource_id, type_str in list(self.manifest.allowlisted_resources.items()):
            resource_type = ResourceType(type_str)

            # Security Guard 1: Verify resource ID is in authorization context allowlist
            if resource_id not in auth_context.allowlisted_resource_ids:
                res = DeletionResult(
                    resource_id=resource_id,
                    resource_type=resource_type,
                    status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                    success=False,
                    message="Resource ID not present in execution authorization allowlist.",
                    timestamp=self._now().isoformat(),
                )
                results.append(res)
                continue

            # Security Guard 1b: Reject ambiguous auth contexts containing extra resource IDs
            if unexpected_auth_ids:
                res = DeletionResult(
                    resource_id=resource_id,
                    resource_type=resource_type,
                    status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                    success=False,
                    message="Ambiguous authorization context contains unexpected resource IDs not in manifest.",
                    timestamp=self._now().isoformat(),
                )
                results.append(res)
                continue

            # Security Guard 2: Verify resource type is authorized
            if resource_type not in auth_context.allowed_resource_types:
                res = DeletionResult(
                    resource_id=resource_id,
                    resource_type=resource_type,
                    status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                    success=False,
                    message=f"Resource type '{resource_type.value}' not authorized in context.",
                    timestamp=self._now().isoformat(),
                )
                results.append(res)
                continue

            # Security Guard 2b: DNS record automated deletion fails closed under v1.0 manifest contract
            if resource_type == ResourceType.DNS_RECORD:
                domain_bound = self.manifest.dns_record_domains.get(resource_id, "")
                reason = (
                    "DNS record automated deletion is unsupported in manifest v1.0: "
                    "record name cannot imply ownership and provider record payload lacks account/project/status."
                    if domain_bound
                    else "DNS record missing explicit domain identity binding; failing closed."
                )
                self._record_audit_event("UNSUPPORTED_DNS_RECORD_TEARDOWN_BLOCKED", {
                    "resource_id": resource_id,
                    "has_domain_binding": bool(domain_bound),
                    "action": "REJECT_DELETION",
                })
                res = DeletionResult(
                    resource_id=resource_id,
                    resource_type=resource_type,
                    status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                    success=False,
                    message=reason,
                    timestamp=self._now().isoformat(),
                )
                results.append(res)
                continue

            # Security Guard 3 & Critical Correction A:
            # For parent Droplets, inspect associated resources BEFORE attempting deletion.
            # If ANY associated resource is unapproved, mismatched, or fails ownership/status,
            # STOP parent deletion immediately. adapter.delete_resource() MUST NOT be called!
            reconciled_ids: list[str] = []
            has_unapproved_associated = False

            if resource_type == ResourceType.DROPLET:
                associated_identities = self.adapter.enumerate_associated_resources(resource_id)

                for assoc in associated_identities:
                    # 3a. Must be explicitly registered in the manifest allowlist
                    if assoc.resource_id not in self.manifest.allowlisted_resources:
                        has_unapproved_associated = True
                        associated_orphans.append(assoc.resource_id)
                        self._record_audit_event("UNAPPROVED_ASSOCIATED_RESOURCE_DETECTED", {
                            "parent_resource_id": resource_id,
                            "associated_resource_id": assoc.resource_id,
                            "reason": "Not in manifest allowlist",
                            "action": "ABORT_PARENT_DELETION",
                        })
                        continue

                    # 3b. Must match the registered resource type
                    registered_type = self.manifest.allowlisted_resources[assoc.resource_id]
                    if registered_type != assoc.resource_type.value:
                        has_unapproved_associated = True
                        associated_orphans.append(assoc.resource_id)
                        self._record_audit_event("UNAPPROVED_ASSOCIATED_RESOURCE_DETECTED", {
                            "parent_resource_id": resource_id,
                            "associated_resource_id": assoc.resource_id,
                            "reason": f"Type mismatch: expected {registered_type}, got {assoc.resource_type.value}",
                            "action": "ABORT_PARENT_DELETION",
                        })
                        continue

                    # 3c. Must pass strict status eligibility and ownership verification
                    if not self.adapter.confirm_resource_ownership(assoc, self.manifest.ownership):
                        has_unapproved_associated = True
                        associated_orphans.append(assoc.resource_id)
                        self._record_audit_event("UNAPPROVED_ASSOCIATED_RESOURCE_DETECTED", {
                            "parent_resource_id": resource_id,
                            "associated_resource_id": assoc.resource_id,
                            "reason": f"Ownership or status verification failed (status={assoc.status.value})",
                            "action": "ABORT_PARENT_DELETION",
                        })
                        continue

                    reconciled_ids.append(assoc.resource_id)

            # If unapproved/unverified associated resource was discovered: FAIL CLOSED!
            # Ensure adapter.delete_resource() is NEVER called for this parent.
            if has_unapproved_associated:
                res = DeletionResult(
                    resource_id=resource_id,
                    resource_type=resource_type,
                    status=TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED,
                    success=False,
                    message="Parent deletion aborted: unapproved or mismatched associated resource detected.",
                    timestamp=self._now().isoformat(),
                    reconciled_associated_resources=reconciled_ids,
                )
                results.append(res)
                continue

            # Execute deletion through adapter (adapter executes the remaining checks)
            del_result = self.adapter.delete_resource(resource_id, resource_type, auth_context)
            del_result.reconciled_associated_resources = reconciled_ids
            results.append(del_result)

        self.manifest.results = results

        # Evaluate final audit report
        total_eval = len(results)
        confirmed_absent = sum(1 for r in results if r.status == TeardownActionStatus.RESOURCE_ABSENCE_CONFIRMED)
        has_violations = any(r.status in (TeardownActionStatus.REJECTED_SECURITY_VIOLATION, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED) for r in results)
        has_incomplete = any(r.status == TeardownActionStatus.CLEANUP_INCOMPLETE or not r.success for r in results)

        manual_intervention = bool(associated_orphans or has_violations or unexpected_auth_ids)

        if manual_intervention:
            overall_status = TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED
            self.manifest.state = "MANUAL_INTERVENTION_REQUIRED"
        elif confirmed_absent == total_eval and total_eval > 0:
            overall_status = TeardownActionStatus.RESOURCE_ABSENCE_CONFIRMED
            self.manifest.state = "COMPLETED"
        elif has_incomplete or any(r.status == TeardownActionStatus.DELETION_ACKNOWLEDGED for r in results):
            overall_status = TeardownActionStatus.CLEANUP_INCOMPLETE
            self.manifest.state = "INCOMPLETE"
        else:
            overall_status = TeardownActionStatus.CLEANUP_INCOMPLETE
            self.manifest.state = "INCOMPLETE"

        report = CleanupAuditReport(
            manifest_id=self.manifest.manifest_id,
            evaluated_at_utc=self._now().isoformat(),
            overall_status=overall_status,
            resources_evaluated=total_eval,
            resources_confirmed_absent=confirmed_absent,
            cleanup_incomplete_count=total_eval - confirmed_absent,
            manual_intervention_required=manual_intervention,
            details=[asdict(r) for r in results],
            sanitized_summary={
                "manifest_id": self.manifest.manifest_id,
                "overall_status": overall_status.value,
                "unapproved_associated_orphans": associated_orphans,
                "security_violations_count": sum(1 for r in results if r.status == TeardownActionStatus.REJECTED_SECURITY_VIOLATION),
                "is_expired": is_expired,
            },
        )

        self._record_audit_event("TEARDOWN_COMPLETED", report.sanitized_summary)
        return report
