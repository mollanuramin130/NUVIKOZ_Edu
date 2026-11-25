"""NUVIKOZ Edu — DigitalOcean API v2 Teardown Adapter.

This module implements the provider-specific adapter for DigitalOcean API v2.
It sits behind the abstract ProviderAdapterInterface and relies on an injectable
HTTP transport to guarantee complete network isolation during tests.

Security Notice:
- Real DigitalOcean API Personal Access Tokens (PATs) grant full account-level read/write
  privileges; DigitalOcean API v2 does NOT provide fine-grained, tag-restricted API tokens.
- Therefore, client-side allowlist enforcement, project matching, and ownership verification
  are critical security boundaries that must fail closed on any discrepancy.
- Zero network calls are made without an explicitly provided transport.
- Secrets are NEVER logged or surfaced in exceptions.
"""

from __future__ import annotations

from datetime import datetime, timezone
import ipaddress
import re
import time
from typing import Any, Callable

from scripts.e2e.teardown_contract import (
    DELETION_ELIGIBLE_STATUSES,
    DeletionResult,
    ExecutionAuthContext,
    HttpTransportProtocol,
    OwnershipContract,
    ProviderAdapterInterface,
    ResourceIdentity,
    ResourceStatus,
    ResourceType,
    TeardownActionStatus,
    TeardownSecurityError,
)


_SAFE_ID_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$")
_DOMAIN_NAME_RE = re.compile(
    r"^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$"
)
_DNS_RECORD_ID_RE = re.compile(r"^[1-9][0-9]{0,19}$")

_SUPPORTED_STATUS_MAP: dict[str, ResourceStatus] = {
    "active": ResourceStatus.ACTIVE,
    "new": ResourceStatus.NEW,
    "off": ResourceStatus.OFF,
    "archive": ResourceStatus.ARCHIVE,
    "deleting": ResourceStatus.DELETING,
    "deleted": ResourceStatus.DELETED,
}


class DigitalOceanAdapter(ProviderAdapterInterface):
    """DigitalOcean API v2 adapter with strict allowlist enforcement and retry.

    Documented DigitalOcean API v2 Ownership Compatibility:
    - GET /v2/droplets/{id} and GET /v2/volumes/{id} do NOT return account_id or project_id.
    - Account identity MUST be resolved via GET /v2/account (user uuid or team.uuid).
    - Project membership MUST be resolved via GET /v2/projects/{project_id}/resources URN matching.
    - Reserved IPs moved from /v2/floating_ips/{ip} to /v2/reserved_ips/{ip} and omit name/tags/status.
    - DNS records require /v2/domains/{domain_name}/records/{record_id} and omit account/project/tags/status.
    """

    BASE_URL = "https://api.digitalocean.com/v2"
    MAX_PROJECT_PAGES = 20

    def __init__(
        self,
        transport: HttpTransportProtocol,
        expected_account_id: str,
        expected_project_id: str = "",
        required_tag: str = "env:nuvikoz-e2e-disposable",
        expected_name_prefix: str = "nuvikoz-e2e-",
        max_retries: int = 3,
        retry_backoff_factor: float = 0.05,
        sleep_fn: Callable[[float], None] | None = None,
        time_provider: Callable[[], datetime] | None = None,
        dns_record_domains: dict[str, str] | None = None,
    ) -> None:
        self.transport = transport
        self.expected_ownership = OwnershipContract(
            expected_account_id=expected_account_id,
            expected_project_id=expected_project_id,
            required_tag=required_tag,
            expected_name_prefix=expected_name_prefix,
        )
        self.max_retries = max_retries
        self.retry_backoff_factor = retry_backoff_factor
        self._sleep = sleep_fn or time.sleep
        self._time_provider = time_provider or (lambda: datetime.now(timezone.utc))
        self.dns_record_domains = dict(dns_record_domains) if dns_record_domains else {}

    def _now(self) -> datetime:
        return self._time_provider()

    @staticmethod
    def _reject_unsafe_path_chars(value: str, label: str) -> None:
        if not isinstance(value, str) or not value:
            raise TeardownSecurityError(f"Empty or non-string {label}.")
        if any(ch in value for ch in ("/", "\\", "%", "?", "#")) or ".." in value or any(ch.isspace() for ch in value):
            raise TeardownSecurityError(f"Unsafe path traversal or encoded fragment rejected in {label}.")

    @classmethod
    def validate_resource_id(cls, resource_id: str, resource_type: ResourceType) -> str:
        """Strictly validate resource identifiers against traversal, encoding, and type rules."""
        cls._reject_unsafe_path_chars(resource_id, f"{resource_type.value} resource_id")

        if resource_type in (ResourceType.DROPLET, ResourceType.VOLUME):
            if not _SAFE_ID_RE.fullmatch(resource_id):
                raise TeardownSecurityError(f"Invalid {resource_type.value} identifier format.")
            return resource_id

        if resource_type in (ResourceType.FLOATING_IP, ResourceType.RESERVED_IP):
            try:
                ip_obj = ipaddress.ip_address(resource_id)
            except ValueError as exc:
                raise TeardownSecurityError("Reserved IP identifier must be a valid IPv4/IPv6 address.") from exc
            if str(ip_obj) != resource_id:
                raise TeardownSecurityError("Non-canonical Reserved IP identifier rejected.")
            return resource_id

        if resource_type == ResourceType.DNS_RECORD:
            if not _DNS_RECORD_ID_RE.fullmatch(resource_id):
                raise TeardownSecurityError("Invalid or ambiguous DNS record ID (must be positive integer string).")
            return resource_id

        raise ValueError(f"Unsupported resource type: {resource_type}")

    @classmethod
    def validate_domain_name(cls, domain_name: str) -> str:
        """Strictly validate a DNS domain name before path construction."""
        cls._reject_unsafe_path_chars(domain_name, "domain_name")
        if len(domain_name) > 253 or not _DOMAIN_NAME_RE.fullmatch(domain_name):
            raise TeardownSecurityError("Invalid or non-canonical domain_name for DigitalOcean DNS endpoint.")
        return domain_name

    @classmethod
    def build_dns_record_path(cls, domain_name: str, record_id: str) -> str:
        """Build the documented /domains/{domain_name}/records/{record_id} path cleanly."""
        valid_domain = cls.validate_domain_name(domain_name)
        valid_record_id = cls.validate_resource_id(record_id, ResourceType.DNS_RECORD)
        return f"/domains/{valid_domain}/records/{valid_record_id}"

    def _request_with_retry(
        self,
        method: str,
        path: str,
        body: dict[str, Any] | None = None,
    ) -> tuple[int, dict[str, Any]]:
        """Execute request with bounded exponential backoff for 429 and 5xx."""
        url = f"{self.BASE_URL}{path}" if path.startswith("/") else f"{self.BASE_URL}/{path}"
        headers = {
            "Accept": "application/json",
            "Content-Type": "application/json",
            # Note: In live environments an Authorization header is supplied by transport,
            # but raw secrets are never constructed or logged here.
        }

        last_status = 0
        last_data: dict[str, Any] = {}

        for attempt in range(self.max_retries + 1):
            try:
                status, data = self.transport.request(method, url, headers=headers, body=body)
                last_status, last_data = status, data

                # Success, 404, or non-retryable client error (400, 401, 403, etc.)
                if status < 500 and status != 429:
                    return status, data

                # Rate limiting (429) or transient server errors (500, 502, 503, 504)
                if attempt < self.max_retries:
                    delay = self.retry_backoff_factor * (2 ** attempt)
                    self._sleep(delay)
                    continue

                return status, data

            except TimeoutError as exc:
                if attempt < self.max_retries:
                    delay = self.retry_backoff_factor * (2 ** attempt)
                    self._sleep(delay)
                    continue
                raise TimeoutError(f"DigitalOcean API request to {method} {path} timed out after {self.max_retries} retries.") from exc
            except Exception as exc:
                if attempt < self.max_retries:
                    delay = self.retry_backoff_factor * (2 ** attempt)
                    self._sleep(delay)
                    continue
                raise RuntimeError(f"DigitalOcean API transport error on {method} {path}: {exc}") from exc

        return last_status, last_data

    @staticmethod
    def _parse_resource_status(item: dict[str, Any]) -> ResourceStatus:
        """Strictly parse provider status without ever inferring ACTIVE from a missing field."""
        if "status" not in item or item["status"] is None:
            return ResourceStatus.UNKNOWN
        raw_status = item["status"]
        if not isinstance(raw_status, str) or not raw_status.strip():
            return ResourceStatus.UNKNOWN
        return _SUPPORTED_STATUS_MAP.get(raw_status.strip().lower(), ResourceStatus.UNKNOWN)

    @staticmethod
    def _parse_string_list(item: dict[str, Any], field_name: str, resource_label: str) -> list[str]:
        """Validate that an optional JSON array field contains strictly strings."""
        if field_name not in item or item[field_name] is None:
            return []
        raw_val = item[field_name]
        if not isinstance(raw_val, list) or any(not isinstance(elem, str) for elem in raw_val):
            raise TeardownSecurityError(
                f"Malformed '{field_name}' list in DigitalOcean {resource_label} payload."
            )
        return list(raw_val)

    def verify_account_context(self) -> str:
        """Resolve verified DigitalOcean account/team UUID via GET /v2/account."""
        status, data = self._request_with_retry("GET", "/account")
        if status != 200 or not isinstance(data, dict):
            return ""
        account = data.get("account")
        if not isinstance(account, dict):
            return ""
        if str(account.get("status", "")).strip().lower() != "active":
            return ""

        team = account.get("team")
        if team is not None:
            if not isinstance(team, dict):
                return ""
            team_uuid = team.get("uuid")
            return team_uuid.strip() if isinstance(team_uuid, str) and team_uuid.strip() else ""

        user_uuid = account.get("uuid")
        return user_uuid.strip() if isinstance(user_uuid, str) and user_uuid.strip() else ""

    def verify_project_membership(
        self,
        resource_id: str,
        resource_type: ResourceType,
        domain_name: str | None = None,
    ) -> str:
        """Verify resource URN membership via GET /v2/projects/{project_id}/resources."""
        project_id = self.expected_ownership.expected_project_id
        if not isinstance(project_id, str) or not _SAFE_ID_RE.fullmatch(project_id):
            return ""

        if resource_type == ResourceType.DROPLET:
            expected_urn = f"do:droplet:{resource_id}"
        elif resource_type == ResourceType.VOLUME:
            expected_urn = f"do:volume:{resource_id}"
        elif resource_type in (ResourceType.FLOATING_IP, ResourceType.RESERVED_IP):
            expected_urn = f"do:floatingip:{resource_id}"
        elif resource_type == ResourceType.DNS_RECORD:
            # DigitalOcean Projects API maps domains (do:domain:{domain}), not individual records.
            # Per-record project membership cannot be established via URN lookup alone.
            return ""
        else:
            return ""

        path = f"/projects/{project_id}/resources"
        allowed_prefix = f"{self.BASE_URL}/projects/{project_id}/resources?"

        for _ in range(self.MAX_PROJECT_PAGES):
            status, data = self._request_with_retry("GET", path)
            if status != 200 or not isinstance(data, dict):
                return ""
            resources = data.get("resources")
            if not isinstance(resources, list):
                return ""

            for entry in resources:
                if (
                    isinstance(entry, dict)
                    and str(entry.get("urn", "")) == expected_urn
                    and str(entry.get("status", "")).strip().lower() == "ok"
                ):
                    return project_id

            links = data.get("links")
            pages = links.get("pages") if isinstance(links, dict) else None
            next_url = pages.get("next") if isinstance(pages, dict) else None
            if isinstance(next_url, str) and next_url.startswith(allowed_prefix):
                path = next_url[len(self.BASE_URL):]
            else:
                break

        return ""

    def _resolve_ownership_ids(
        self,
        item: dict[str, Any],
        resource_id: str,
        resource_type: ResourceType,
    ) -> tuple[str, str]:
        """Resolve verified account and project IDs without trusting test-only payload fields."""
        verified_account = self.verify_account_context()
        if "account_id" in item and str(item.get("account_id", "")) != verified_account:
            verified_account = ""

        verified_project = self.verify_project_membership(resource_id, resource_type)
        if resource_type in (ResourceType.FLOATING_IP, ResourceType.RESERVED_IP):
            # DigitalOcean Reserved IPs document project_id directly; require agreement with Projects API.
            payload_proj = item.get("project_id")
            if not isinstance(payload_proj, str) or not payload_proj or payload_proj != verified_project:
                verified_project = ""
        elif "project_id" in item and str(item.get("project_id", "")) != verified_project:
            verified_project = ""

        return verified_account, verified_project

    def inspect_resource(
        self,
        resource_id: str,
        resource_type: ResourceType,
        domain_name: str | None = None,
    ) -> ResourceIdentity:
        """Inspect a single cloud resource by validated ID and type."""
        valid_id = self.validate_resource_id(resource_id, resource_type)

        if resource_type == ResourceType.DROPLET:
            path = f"/droplets/{valid_id}"
        elif resource_type == ResourceType.VOLUME:
            path = f"/volumes/{valid_id}"
        elif resource_type in (ResourceType.FLOATING_IP, ResourceType.RESERVED_IP):
            path = f"/reserved_ips/{valid_id}"
        elif resource_type == ResourceType.DNS_RECORD:
            resolved_domain = domain_name if domain_name is not None else self.dns_record_domains.get(valid_id, "")
            if not isinstance(resolved_domain, str) or not resolved_domain.strip():
                raise TeardownSecurityError(
                    "DNS record inspection rejected: explicit validated domain identity is required."
                )
            path = self.build_dns_record_path(resolved_domain.strip(), valid_id)
        else:
            raise ValueError(f"Unsupported resource type: {resource_type}")

        status, data = self._request_with_retry("GET", path)

        if status == 404:
            return ResourceIdentity(
                resource_id=valid_id,
                resource_type=resource_type,
                name="",
                account_id="",
                project_id="",
                tags=[],
                status=ResourceStatus.ABSENT,
                associated_resource_ids=[],
            )

        if status != 200 or not isinstance(data, dict):
            raise RuntimeError(f"Failed to inspect {resource_type.value} resource {valid_id} (HTTP {status}).")

        # Parse resource data according to documented DigitalOcean API v2 schema
        if resource_type == ResourceType.DROPLET:
            item = data.get("droplet")
            if not isinstance(item, dict):
                raise TeardownSecurityError("Unexpected DigitalOcean API response shape for droplet.")
            returned_id = str(item.get("id", ""))
            if not returned_id or returned_id != str(valid_id):
                raise TeardownSecurityError(
                    f"Provider returned resource ID '{returned_id}' which does not match requested ID '{valid_id}'."
                )
            raw_name = item.get("name", "")
            if not isinstance(raw_name, str):
                raise TeardownSecurityError("Malformed 'name' field in DigitalOcean droplet payload.")
            tags = self._parse_string_list(item, "tags", "droplet")
            volume_ids = self._parse_string_list(item, "volume_ids", "droplet")
            for v_id in volume_ids:
                self.validate_resource_id(v_id, ResourceType.VOLUME)

            res_status = self._parse_resource_status(item)
            account_id, project_id = self._resolve_ownership_ids(item, valid_id, resource_type)

            return ResourceIdentity(
                resource_id=valid_id,
                resource_type=resource_type,
                name=raw_name,
                account_id=account_id,
                project_id=project_id,
                tags=tags,
                status=res_status,
                associated_resource_ids=volume_ids,
            )

        if resource_type == ResourceType.VOLUME:
            item = data.get("volume")
            if not isinstance(item, dict):
                raise TeardownSecurityError("Unexpected DigitalOcean API response shape for volume.")
            returned_id = str(item.get("id", ""))
            if not returned_id or returned_id != str(valid_id):
                raise TeardownSecurityError(
                    f"Provider returned resource ID '{returned_id}' which does not match requested ID '{valid_id}'."
                )
            raw_name = item.get("name", "")
            if not isinstance(raw_name, str):
                raise TeardownSecurityError("Malformed 'name' field in DigitalOcean volume payload.")
            tags = self._parse_string_list(item, "tags", "volume")
            droplet_ids_raw = item.get("droplet_ids", [])
            if droplet_ids_raw is not None and not isinstance(droplet_ids_raw, list):
                raise TeardownSecurityError("Malformed 'droplet_ids' field in DigitalOcean volume payload.")

            # Official DO Volume payloads omit 'status'; missing status resolves to UNKNOWN (fail closed).
            res_status = self._parse_resource_status(item)
            account_id, project_id = self._resolve_ownership_ids(item, valid_id, resource_type)

            return ResourceIdentity(
                resource_id=valid_id,
                resource_type=resource_type,
                name=raw_name,
                account_id=account_id,
                project_id=project_id,
                tags=tags,
                status=res_status,
                associated_resource_ids=[],
            )

        if resource_type in (ResourceType.FLOATING_IP, ResourceType.RESERVED_IP):
            item = data.get("reserved_ip")
            if not isinstance(item, dict):
                raise TeardownSecurityError("Unexpected DigitalOcean API response shape for reserved_ip.")
            returned_ip = str(item.get("ip", ""))
            if not returned_ip or returned_ip != str(valid_id):
                raise TeardownSecurityError(
                    f"Provider returned reserved IP '{returned_ip}' which does not match requested IP '{valid_id}'."
                )

            raw_name = item.get("name", "")
            if raw_name is None:
                raw_name = ""
            elif not isinstance(raw_name, str):
                raise TeardownSecurityError("Malformed 'name' field in DigitalOcean reserved_ip payload.")

            tags = self._parse_string_list(item, "tags", "reserved_ip")
            droplet_obj = item.get("droplet")
            if droplet_obj is not None and not isinstance(droplet_obj, dict):
                raise TeardownSecurityError("Malformed 'droplet' assignment in DigitalOcean reserved_ip payload.")
            droplet_id = str(droplet_obj.get("id", "")) if isinstance(droplet_obj, dict) and droplet_obj.get("id") else ""
            associated = [droplet_id] if droplet_id else []

            # Reserved IPs lack 'status' in DO API; missing status or locked=True remains non-eligible.
            res_status = ResourceStatus.UNKNOWN if item.get("locked") is True else self._parse_resource_status(item)
            account_id, project_id = self._resolve_ownership_ids(item, valid_id, resource_type)

            return ResourceIdentity(
                resource_id=valid_id,
                resource_type=resource_type,
                name=raw_name,
                account_id=account_id,
                project_id=project_id,
                tags=tags,
                status=res_status,
                associated_resource_ids=associated,
            )

        if resource_type == ResourceType.DNS_RECORD:
            item = data.get("domain_record")
            if not isinstance(item, dict):
                raise TeardownSecurityError("Unexpected DigitalOcean API response shape for domain_record.")
            returned_id = str(item.get("id", ""))
            if not returned_id or returned_id != str(valid_id):
                raise TeardownSecurityError(
                    f"Provider returned record ID '{returned_id}' which does not match requested ID '{valid_id}'."
                )
            raw_name = item.get("name", "")
            if not isinstance(raw_name, str):
                raise TeardownSecurityError("Malformed 'name' field in DigitalOcean domain_record payload.")

            tags = self._parse_string_list(item, "tags", "domain_record")
            res_status = self._parse_resource_status(item)
            account_id, project_id = self._resolve_ownership_ids(item, valid_id, resource_type)

            return ResourceIdentity(
                resource_id=valid_id,
                resource_type=resource_type,
                name=raw_name,
                account_id=account_id,
                project_id=project_id,
                tags=tags,
                status=res_status,
                associated_resource_ids=[],
            )

        raise ValueError(f"Unhandled resource type: {resource_type}")

    def confirm_resource_ownership(self, resource: ResourceIdentity, expected_ownership: OwnershipContract) -> bool:
        """Verify the resource satisfies status eligibility and all ownership criteria."""
        return resource.matches_ownership(expected_ownership)

    def delete_resource(
        self,
        resource_id: str,
        resource_type: ResourceType,
        auth_context: ExecutionAuthContext,
        domain_name: str | None = None,
    ) -> DeletionResult:
        """Execute deletion following strict preflight authorization, status, and ownership checks."""
        now = self._now()
        timestamp = now.isoformat()

        # Check 0: Strictly validate execution authorization context
        auth_context.validate(current_time=now)

        # Check 0a: Reject unsafe resource identifiers before any inspection or path construction
        try:
            valid_id = self.validate_resource_id(resource_id, resource_type)
        except TeardownSecurityError as exc:
            return DeletionResult(
                resource_id=str(resource_id),
                resource_type=resource_type,
                status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                success=False,
                message=str(exc),
                timestamp=timestamp,
            )

        # Check 1: Exact resource-ID allowlist match
        if valid_id not in auth_context.allowlisted_resource_ids:
            return DeletionResult(
                resource_id=valid_id,
                resource_type=resource_type,
                status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                success=False,
                message=f"Resource ID '{valid_id}' is not in the execution allowlist.",
                timestamp=timestamp,
            )

        # Check 2: Expected resource type
        if resource_type not in auth_context.allowed_resource_types:
            return DeletionResult(
                resource_id=valid_id,
                resource_type=resource_type,
                status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                success=False,
                message=f"Resource type '{resource_type.value}' is not authorized.",
                timestamp=timestamp,
            )

        # Check 2b: DNS record automated deletion fails closed unless a dedicated signed domain-record contract exists
        if resource_type == ResourceType.DNS_RECORD:
            return DeletionResult(
                resource_id=valid_id,
                resource_type=resource_type,
                status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                success=False,
                message=(
                    "Automated DNS record deletion is unsupported fail-closed: "
                    "record name cannot imply ownership and v1.0 manifest lacks domain-record proof."
                ),
                timestamp=timestamp,
            )

        # Check 3: Re-inspect resource immediately prior to deletion
        identity = self.inspect_resource(valid_id, resource_type, domain_name=domain_name)

        # Check 3a: Verify identity consistency between requested and inspected resource
        if str(identity.resource_id) != str(valid_id) or identity.resource_type != resource_type:
            return DeletionResult(
                resource_id=valid_id,
                resource_type=resource_type,
                status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                success=False,
                message="Resource identity inconsistency detected during preflight inspection.",
                timestamp=timestamp,
            )

        # Check 3b: Independently verified HTTP 404 absence is idempotently complete
        if identity.status == ResourceStatus.ABSENT:
            return DeletionResult(
                resource_id=valid_id,
                resource_type=resource_type,
                status=TeardownActionStatus.RESOURCE_ABSENCE_CONFIRMED,
                success=True,
                message="Resource was verified to be absent prior to deletion request.",
                timestamp=timestamp,
            )

        # Check 3c: Explicitly reject UNKNOWN, DELETING, and any non-eligible status BEFORE ownership/dry-run/DELETE
        if identity.status == ResourceStatus.UNKNOWN:
            return DeletionResult(
                resource_id=valid_id,
                resource_type=resource_type,
                status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                success=False,
                message="Resource status is UNKNOWN; refusing destructive operation (fail-closed).",
                timestamp=timestamp,
            )

        if identity.status == ResourceStatus.DELETING:
            return DeletionResult(
                resource_id=valid_id,
                resource_type=resource_type,
                status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                success=False,
                message="Unsupported DELETING state: cannot authorize a second deletion while absence is unconfirmed.",
                timestamp=timestamp,
            )

        if identity.status not in DELETION_ELIGIBLE_STATUSES:
            return DeletionResult(
                resource_id=valid_id,
                resource_type=resource_type,
                status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                success=False,
                message=f"Unsupported resource status '{identity.status.value}' is not eligible for deletion.",
                timestamp=timestamp,
            )

        # Check 4: Confirm ownership of the re-inspected resource
        if not self.confirm_resource_ownership(identity, self.expected_ownership):
            return DeletionResult(
                resource_id=valid_id,
                resource_type=resource_type,
                status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                success=False,
                message="Resource ownership verification failed (tag, name prefix, project, or account mismatch).",
                timestamp=timestamp,
            )

        # Check 5: Explicit authorization context confirmation
        if not auth_context.authorized_by.strip():
            return DeletionResult(
                resource_id=valid_id,
                resource_type=resource_type,
                status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                success=False,
                message="Missing explicit authorizer in execution context.",
                timestamp=timestamp,
            )

        # Check 6: Dry-run simulation vs Live Execution Gate
        if auth_context.dry_run:
            return DeletionResult(
                resource_id=valid_id,
                resource_type=resource_type,
                status=TeardownActionStatus.DELETION_ACKNOWLEDGED,
                success=True,
                message="Dry-run deletion simulated successfully. No cloud state mutated.",
                timestamp=timestamp,
            )

        # Live Execution Safety Gate
        from scripts.e2e.teardown_contract import ExternalTeardownRunner
        if not ExternalTeardownRunner.LIVE_EXECUTION_ENABLED:
            raise TeardownSecurityError(
                "CRITICAL: Live provider execution is globally disabled. "
                "Owner authorization required to enable LIVE_EXECUTION_ENABLED."
            )

        # Build destructive endpoint strictly from validated identifiers
        if resource_type == ResourceType.DROPLET:
            path = f"/droplets/{valid_id}"
        elif resource_type == ResourceType.VOLUME:
            path = f"/volumes/{valid_id}"
        elif resource_type in (ResourceType.FLOATING_IP, ResourceType.RESERVED_IP):
            path = f"/reserved_ips/{valid_id}"
        else:
            raise ValueError(f"Unsupported resource type for deletion: {resource_type}")

        status, _ = self._request_with_retry("DELETE", path)

        if status in (204, 202, 200):
            # Verify absence immediately after deletion acknowledgement
            post_status = self.check_deletion_status(valid_id, resource_type)
            if post_status == ResourceStatus.ABSENT:
                action_status = TeardownActionStatus.RESOURCE_ABSENCE_CONFIRMED
                msg = "Resource deletion acknowledged and absence confirmed."
            else:
                action_status = TeardownActionStatus.DELETION_ACKNOWLEDGED
                msg = f"Resource deletion acknowledged (status: {post_status.value})."

            return DeletionResult(
                resource_id=valid_id,
                resource_type=resource_type,
                status=action_status,
                success=True,
                message=msg,
                timestamp=self._now().isoformat(),
            )

        return DeletionResult(
            resource_id=valid_id,
            resource_type=resource_type,
            status=TeardownActionStatus.CLEANUP_INCOMPLETE,
            success=False,
            message=f"Deletion request failed with HTTP {status}.",
            timestamp=self._now().isoformat(),
        )

    def check_deletion_status(
        self,
        resource_id: str,
        resource_type: ResourceType,
        domain_name: str | None = None,
    ) -> ResourceStatus:
        """Check whether the resource has ceased to exist."""
        identity = self.inspect_resource(resource_id, resource_type, domain_name=domain_name)
        return identity.status

    def enumerate_associated_resources(self, parent_resource_id: str) -> list[ResourceIdentity]:
        """Enumerate associated resources (volumes) for reconciliation."""
        droplet = self.inspect_resource(parent_resource_id, ResourceType.DROPLET)
        if droplet.status == ResourceStatus.ABSENT:
            return []

        associated: list[ResourceIdentity] = []
        for v_id in droplet.associated_resource_ids:
            try:
                v_ident = self.inspect_resource(v_id, ResourceType.VOLUME)
                associated.append(v_ident)
            except Exception:
                # If volume cannot be inspected or fails validation, fail-closed identity without synthesizing ownership
                associated.append(ResourceIdentity(
                    resource_id=v_id,
                    resource_type=ResourceType.VOLUME,
                    name="",
                    account_id="",
                    project_id="",
                    tags=[],
                    status=ResourceStatus.UNKNOWN,
                ))

        return associated
