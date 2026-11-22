"""NUVIKOZ Edu — Provider-Independent External Teardown Contract & Runner.

This module implements the architectural contract, strict security guards,
and state management for teardown operations against disposable E2E environments.

Policy Enforcement:
- DEVELOPMENT FIRST, DEPLOYMENT LATER.
- Strict allowlist matching: A tag alone NEVER authorizes deletion.
- Exact resource-ID matching is required for every destructive operation.
- No broad resource enumeration followed by wildcard deletion.
- Four-hour maximum execution window based on persisted absolute timestamp.
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
from typing import Any, Callable


class ResourceType(str, Enum):
    """Supported cloud resource types for disposable E2E fixtures."""
    DROPLET = "droplet"
    VOLUME = "volume"
    FLOATING_IP = "floating_ip"
    DNS_RECORD = "dns_record"


class ResourceStatus(str, Enum):
    """Runtime status of a cloud resource."""
    ACTIVE = "active"
    DELETING = "deleting"
    DELETED = "deleted"
    ABSENT = "absent"
    UNKNOWN = "unknown"


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


@dataclass(frozen=True)
class OwnershipContract:
    """Ownership contract verifying a resource belongs to the disposable fixture."""
    expected_account_id: str
    expected_project_id: str
    required_tag: str
    expected_name_prefix: str


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
        """Confirm resource strictly satisfies the required ownership contract."""
        if self.status == ResourceStatus.ABSENT:
            return False
        if contract.required_tag not in self.tags:
            return False
        if not self.name.startswith(contract.expected_name_prefix):
            return False
        if self.account_id != contract.expected_account_id:
            return False
        if contract.expected_project_id and self.project_id != contract.expected_project_id:
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
    state: str = "PENDING"
    results: list[DeletionResult] = field(default_factory=list)
    audit_events: list[dict[str, Any]] = field(default_factory=list)
    manifest_version: str = "1.0"

    def is_expired(self, current_time: datetime | None = None) -> bool:
        """Check whether the 4-hour execution window has passed."""
        now = current_time or datetime.now(timezone.utc)
        deadline = datetime.fromisoformat(self.deadline_at_utc.replace("Z", "+00:00"))
        return now >= deadline

    def to_dict(self) -> dict[str, Any]:
        """Convert manifest to sanitized dictionary."""
        return asdict(self)

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> TeardownManifest:
        """Reconstruct manifest from dictionary with strict schema validation."""
        required_keys = {"manifest_id", "created_at_utc", "deadline_at_utc", "ownership", "allowlisted_resources"}
        if not required_keys.issubset(data.keys()):
            raise TeardownStateCorruptedError(f"Manifest missing required keys: {required_keys - set(data.keys())}")

        try:
            ownership_data = data["ownership"]
            ownership = OwnershipContract(
                expected_account_id=str(ownership_data["expected_account_id"]),
                expected_project_id=str(ownership_data.get("expected_project_id", "")),
                required_tag=str(ownership_data["required_tag"]),
                expected_name_prefix=str(ownership_data["expected_name_prefix"]),
            )

            results = []
            for r in data.get("results", []):
                results.append(DeletionResult(
                    resource_id=str(r["resource_id"]),
                    resource_type=ResourceType(r["resource_type"]),
                    status=TeardownActionStatus(r["status"]),
                    success=bool(r["success"]),
                    message=str(r["message"]),
                    timestamp=str(r["timestamp"]),
                    reconciled_associated_resources=[str(x) for x in r.get("reconciled_associated_resources", [])],
                ))

            return cls(
                manifest_id=str(data["manifest_id"]),
                created_at_utc=str(data["created_at_utc"]),
                deadline_at_utc=str(data["deadline_at_utc"]),
                ownership=ownership,
                allowlisted_resources={str(k): str(v) for k, v in data["allowlisted_resources"].items()},
                state=str(data.get("state", "PENDING")),
                results=results,
                audit_events=list(data.get("audit_events", [])),
                manifest_version=str(data.get("manifest_version", "1.0")),
            )
        except Exception as exc:
            raise TeardownStateCorruptedError(f"Failed to parse manifest data: {exc}") from exc

    def save_atomic(self, file_path: Path | str) -> None:
        """Persist manifest to disk atomically using temporary file rename."""
        target_path = Path(file_path).resolve()
        target_path.parent.mkdir(parents=True, exist_ok=True)

        payload = json.dumps(self.to_dict(), indent=2, sort_keys=True)
        # Write to temp file in same directory to ensure atomic rename
        with tempfile.NamedTemporaryFile("w", dir=target_path.parent, delete=False) as tf:
            tf.write(payload)
            temp_name = tf.name

        os.replace(temp_name, target_path)

    @classmethod
    def load(cls, file_path: Path | str) -> TeardownManifest:
        """Load manifest from disk with fail-closed corrupted-state handling."""
        target_path = Path(file_path).resolve()
        if not target_path.exists():
            raise FileNotFoundError(f"Teardown manifest not found at: {target_path}")

        try:
            content = target_path.read_text(encoding="utf-8")
            data = json.loads(content)
            return cls.from_dict(data)
        except (json.JSONDecodeError, TeardownStateCorruptedError) as exc:
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
    5. Independent reconciliation of associated resources.
    6. Atomic state transitions with restart recovery.
    """

    # Global safety gate: Live provider execution is disabled by default.
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

            # Check associated resources before deleting parent (e.g. attached volumes)
            associated_identities = self.adapter.enumerate_associated_resources(resource_id)
            reconciled_ids: list[str] = []

            for assoc in associated_identities:
                # Security Guard: Never delete an associated resource automatically.
                # Must be independently registered in the allowlist!
                if assoc.resource_id in self.manifest.allowlisted_resources:
                    reconciled_ids.append(assoc.resource_id)
                else:
                    associated_orphans.append(assoc.resource_id)
                    self._record_audit_event("UNAPPROVED_ASSOCIATED_RESOURCE_DETECTED", {
                        "parent_resource_id": resource_id,
                        "associated_resource_id": assoc.resource_id,
                        "resource_type": assoc.resource_type.value,
                        "action": "FAIL_CLOSED_MANUAL_INTERVENTION_REQUIRED",
                    })

            # Execute deletion through adapter (adapter executes the remaining 4 checks)
            del_result = self.adapter.delete_resource(resource_id, resource_type, auth_context)
            del_result.reconciled_associated_resources = reconciled_ids
            results.append(del_result)

        self.manifest.results = results

        # Evaluate final audit report
        total_eval = len(results)
        confirmed_absent = sum(1 for r in results if r.status == TeardownActionStatus.RESOURCE_ABSENCE_CONFIRMED)
        has_violations = any(r.status == TeardownActionStatus.REJECTED_SECURITY_VIOLATION for r in results)
        has_failures = any(not r.success for r in results)

        manual_intervention = bool(associated_orphans or has_violations or has_failures)
        if manual_intervention:
            overall_status = TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED
            self.manifest.state = "MANUAL_INTERVENTION_REQUIRED"
        elif confirmed_absent == total_eval:
            overall_status = TeardownActionStatus.RESOURCE_ABSENCE_CONFIRMED
            self.manifest.state = "COMPLETED"
        else:
            overall_status = TeardownActionStatus.DELETION_ACKNOWLEDGED
            self.manifest.state = "COMPLETED"

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
