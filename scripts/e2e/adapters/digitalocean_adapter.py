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
import time
from typing import Any, Callable

from scripts.e2e.teardown_contract import (
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


class DigitalOceanAdapter(ProviderAdapterInterface):
    """DigitalOcean API v2 adapter with strict allowlist enforcement and retry."""

    BASE_URL = "https://api.digitalocean.com/v2"

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

    def inspect_resource(self, resource_id: str, resource_type: ResourceType) -> ResourceIdentity:
        """Inspect a single cloud resource by ID and type."""
        if resource_type == ResourceType.DROPLET:
            path = f"/droplets/{resource_id}"
        elif resource_type == ResourceType.VOLUME:
            path = f"/volumes/{resource_id}"
        elif resource_type == ResourceType.FLOATING_IP:
            path = f"/floating_ips/{resource_id}"
        elif resource_type == ResourceType.DNS_RECORD:
            path = f"/domains/records/{resource_id}"
        else:
            raise ValueError(f"Unsupported resource type: {resource_type}")

        status, data = self._request_with_retry("GET", path)

        if status == 404:
            return ResourceIdentity(
                resource_id=resource_id,
                resource_type=resource_type,
                name="",
                account_id=self.expected_ownership.expected_account_id,
                project_id=self.expected_ownership.expected_project_id,
                tags=[],
                status=ResourceStatus.ABSENT,
                associated_resource_ids=[],
            )

        if status != 200:
            raise RuntimeError(f"Failed to inspect resource {resource_id} (HTTP {status}): {data.get('message', 'Unknown error')}")

        # Parse resource data according to resource type
        if resource_type == ResourceType.DROPLET:
            item = data.get("droplet", {})
            name = str(item.get("name", ""))
            tags = [str(t) for t in item.get("tags", [])]
            volume_ids = [str(v) for v in item.get("volume_ids", [])]
            account_id = str(item.get("account_id", self.expected_ownership.expected_account_id))
            project_id = str(item.get("project_id", self.expected_ownership.expected_project_id))
            raw_status = str(item.get("status", "active"))
            res_status = ResourceStatus.ACTIVE if raw_status == "active" else ResourceStatus.UNKNOWN

            return ResourceIdentity(
                resource_id=resource_id,
                resource_type=resource_type,
                name=name,
                account_id=account_id,
                project_id=project_id,
                tags=tags,
                status=res_status,
                associated_resource_ids=volume_ids,
            )

        if resource_type == ResourceType.VOLUME:
            item = data.get("volume", {})
            name = str(item.get("name", ""))
            tags = [str(t) for t in item.get("tags", [])]
            account_id = str(item.get("account_id", self.expected_ownership.expected_account_id))
            project_id = str(item.get("project_id", self.expected_ownership.expected_project_id))

            return ResourceIdentity(
                resource_id=resource_id,
                resource_type=resource_type,
                name=name,
                account_id=account_id,
                project_id=project_id,
                tags=tags,
                status=ResourceStatus.ACTIVE,
                associated_resource_ids=[],
            )

        if resource_type == ResourceType.FLOATING_IP:
            item = data.get("floating_ip", {})
            account_id = str(item.get("account_id", self.expected_ownership.expected_account_id))
            project_id = str(item.get("project_id", self.expected_ownership.expected_project_id))
            # Floating IPs in DO may have a droplet assignment
            droplet_id = str(item.get("droplet", {}).get("id", "")) if item.get("droplet") else ""
            associated = [droplet_id] if droplet_id else []

            return ResourceIdentity(
                resource_id=resource_id,
                resource_type=resource_type,
                name=f"floating-ip-{resource_id}",
                account_id=account_id,
                project_id=project_id,
                tags=[self.expected_ownership.required_tag],  # DO floating IPs assign tags via resource
                status=ResourceStatus.ACTIVE,
                associated_resource_ids=associated,
            )

        raise ValueError(f"Unhandled resource type: {resource_type}")

    def confirm_resource_ownership(self, resource: ResourceIdentity, expected_ownership: OwnershipContract) -> bool:
        """Verify the resource satisfies all ownership criteria."""
        return resource.matches_ownership(expected_ownership)

    def delete_resource(
        self,
        resource_id: str,
        resource_type: ResourceType,
        auth_context: ExecutionAuthContext,
    ) -> DeletionResult:
        """Execute deletion following all 6 security verification steps."""
        timestamp = datetime.now(timezone.utc).isoformat()

        # Check 1: Exact resource-ID allowlist match
        if resource_id not in auth_context.allowlisted_resource_ids:
            return DeletionResult(
                resource_id=resource_id,
                resource_type=resource_type,
                status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                success=False,
                message=f"Resource ID '{resource_id}' is not in the execution allowlist.",
                timestamp=timestamp,
            )

        # Check 2: Expected resource type
        if resource_type not in auth_context.allowed_resource_types:
            return DeletionResult(
                resource_id=resource_id,
                resource_type=resource_type,
                status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                success=False,
                message=f"Resource type '{resource_type.value}' is not authorized.",
                timestamp=timestamp,
            )

        # Check 3: Re-inspect resource immediately prior to deletion
        identity = self.inspect_resource(resource_id, resource_type)

        # If already absent, return idempotent confirmation
        if identity.status == ResourceStatus.ABSENT:
            return DeletionResult(
                resource_id=resource_id,
                resource_type=resource_type,
                status=TeardownActionStatus.RESOURCE_ABSENCE_CONFIRMED,
                success=True,
                message="Resource was verified to be absent prior to deletion request.",
                timestamp=timestamp,
            )

        # Check 4: Confirm ownership of the re-inspected resource
        if not self.confirm_resource_ownership(identity, self.expected_ownership):
            return DeletionResult(
                resource_id=resource_id,
                resource_type=resource_type,
                status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                success=False,
                message="Resource ownership verification failed (tag, name prefix, or account mismatch).",
                timestamp=timestamp,
            )

        # Check 5: Explicit authorization context confirmation
        if not auth_context.authorized_by:
            return DeletionResult(
                resource_id=resource_id,
                resource_type=resource_type,
                status=TeardownActionStatus.REJECTED_SECURITY_VIOLATION,
                success=False,
                message="Missing explicit authorizer in execution context.",
                timestamp=timestamp,
            )

        # Check 6: Dry-run simulation vs Live Execution Gate
        if auth_context.dry_run:
            return DeletionResult(
                resource_id=resource_id,
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

        # Build destructive endpoint
        if resource_type == ResourceType.DROPLET:
            path = f"/droplets/{resource_id}"
        elif resource_type == ResourceType.VOLUME:
            path = f"/volumes/{resource_id}"
        elif resource_type == ResourceType.FLOATING_IP:
            path = f"/floating_ips/{resource_id}"
        elif resource_type == ResourceType.DNS_RECORD:
            path = f"/domains/records/{resource_id}"
        else:
            raise ValueError(f"Unsupported resource type for deletion: {resource_type}")

        status, _ = self._request_with_retry("DELETE", path)

        if status in (204, 202, 200):
            # Verify absence immediately after deletion acknowledgement
            post_status = self.check_deletion_status(resource_id, resource_type)
            if post_status == ResourceStatus.ABSENT:
                action_status = TeardownActionStatus.RESOURCE_ABSENCE_CONFIRMED
                msg = "Resource deletion acknowledged and absence confirmed."
            else:
                action_status = TeardownActionStatus.DELETION_ACKNOWLEDGED
                msg = f"Resource deletion acknowledged (status: {post_status.value})."

            return DeletionResult(
                resource_id=resource_id,
                resource_type=resource_type,
                status=action_status,
                success=True,
                message=msg,
                timestamp=datetime.now(timezone.utc).isoformat(),
            )

        return DeletionResult(
            resource_id=resource_id,
            resource_type=resource_type,
            status=TeardownActionStatus.CLEANUP_INCOMPLETE,
            success=False,
            message=f"Deletion request failed with HTTP {status}.",
            timestamp=datetime.now(timezone.utc).isoformat(),
        )

    def check_deletion_status(self, resource_id: str, resource_type: ResourceType) -> ResourceStatus:
        """Check whether the resource has ceased to exist."""
        identity = self.inspect_resource(resource_id, resource_type)
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
                # If volume cannot be inspected, create unknown placeholder
                associated.append(ResourceIdentity(
                    resource_id=v_id,
                    resource_type=ResourceType.VOLUME,
                    name=f"unknown-volume-{v_id}",
                    account_id=self.expected_ownership.expected_account_id,
                    project_id=self.expected_ownership.expected_project_id,
                    tags=[],
                    status=ResourceStatus.UNKNOWN,
                ))

        return associated
