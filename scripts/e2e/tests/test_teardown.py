"""NUVIKOZ Edu — Disposable E2E External Teardown Test Suite.

Verifies the 44-point acceptance and security hardening matrix (TC-01 through TC-44)
using 100% mocked transports, deterministic injected fake time, and zero network access.
"""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
import json
from pathlib import Path
import tempfile
from typing import Any
import unittest

from scripts.e2e.adapters.digitalocean_adapter import DigitalOceanAdapter
from scripts.e2e.teardown_contract import (
    CleanupAuditReport,
    ExecutionAuthContext,
    ExternalTeardownRunner,
    HttpTransportProtocol,
    OwnershipContract,
    ResourceIdentity,
    ResourceStatus,
    ResourceType,
    TeardownActionStatus,
    TeardownManifest,
    TeardownSecurityError,
    TeardownStateCorruptedError,
)


class MockHttpTransport(HttpTransportProtocol):
    """Configurable in-memory mock HTTP transport."""

    def __init__(self) -> None:
        self.routes: dict[tuple[str, str], Any] = {}
        self.call_history: list[dict[str, Any]] = []

    def set_route(self, method: str, path: str, response: Any) -> None:
        self.routes[(method, path)] = response

    def request(
        self,
        method: str,
        url: str,
        headers: dict[str, str] | None = None,
        body: dict[str, Any] | None = None,
    ) -> tuple[int, dict[str, Any]]:
        self.call_history.append({
            "method": method,
            "url": url,
            "headers": headers or {},
            "body": body,
        })

        path = url.replace("https://api.digitalocean.com/v2", "")
        key = (method, path)

        if key in self.routes:
            resp = self.routes[key]
            if callable(resp):
                return resp()
            if isinstance(resp, Exception):
                raise resp
            return resp

        return 404, {"message": "Resource not found in mock routes"}


class TestTeardownRunner(unittest.TestCase):
    """44-case acceptance and security regression suite covering TC-01 to TC-44."""

    def setUp(self) -> None:
        self.transport = MockHttpTransport()
        self.fake_now = datetime(2026, 11, 22, 12, 0, 0, tzinfo=timezone.utc)
        self.ownership = OwnershipContract(
            expected_account_id="acc-nuvikoz-dev",
            expected_project_id="proj-e2e-disposable",
            required_tag="env:nuvikoz-e2e-disposable",
            expected_name_prefix="nuvikoz-e2e-",
        )
        self.adapter = DigitalOceanAdapter(
            transport=self.transport,
            expected_account_id=self.ownership.expected_account_id,
            expected_project_id=self.ownership.expected_project_id,
            required_tag=self.ownership.required_tag,
            expected_name_prefix=self.ownership.expected_name_prefix,
            max_retries=3,
            retry_backoff_factor=0.001,  # Fast retries for testing
            sleep_fn=lambda _: None,     # No-op sleep during test
            time_provider=lambda: self.fake_now,
        )
        # Official DigitalOcean API v2 account and project-resources ownership routes
        self._register_default_ownership_routes()

    def _register_default_ownership_routes(self) -> None:
        """Register official GET /v2/account and GET /v2/projects/{id}/resources mock routes."""
        self.transport.set_route("GET", "/account", (200, {
            "account": {
                "uuid": "acc-nuvikoz-dev",
                "status": "active",
                "email_verified": True,
            }
        }))
        self.transport.set_route("GET", "/projects/proj-e2e-disposable/resources", (200, {
            "resources": [
                {"urn": "do:droplet:droplet-1001", "status": "ok"},
                {"urn": "do:droplet:droplet-1002", "status": "ok"},
                {"urn": "do:volume:vol-2001", "status": "ok"},
            ]
        }))

    def _create_standard_manifest(
        self,
        resource_id: str = "droplet-1001",
        resource_type: ResourceType = ResourceType.DROPLET,
        deadline_offset_hours: int = 4,
    ) -> TeardownManifest:
        created_at = self.fake_now.isoformat()
        deadline = (self.fake_now + timedelta(hours=deadline_offset_hours)).isoformat()
        return TeardownManifest(
            manifest_id="manifest-e2e-test-01",
            created_at_utc=created_at,
            deadline_at_utc=deadline,
            ownership=self.ownership,
            allowlisted_resources={resource_id: resource_type.value},
        )

    def _mock_droplet_response(
        self,
        droplet_id: str = "droplet-1001",
        name: str = "nuvikoz-e2e-vps",
        tags: list[str] | None = None,
        account_id: str = "acc-nuvikoz-dev",
        project_id: str = "proj-e2e-disposable",
        volume_ids: list[str] | None = None,
    ) -> dict[str, Any]:
        return {
            "droplet": {
                "id": droplet_id,
                "name": name,
                "tags": tags if tags is not None else ["env:nuvikoz-e2e-disposable"],
                "account_id": account_id,
                "project_id": project_id,
                "volume_ids": volume_ids or [],
                "status": "active",
            }
        }

    def _mock_volume_response(
        self,
        volume_id: str = "vol-2001",
        name: str = "nuvikoz-e2e-data-vol",
        tags: list[str] | None = None,
        account_id: str = "acc-nuvikoz-dev",
        project_id: str = "proj-e2e-disposable",
        status: str = "active",
    ) -> dict[str, Any]:
        return {
            "volume": {
                "id": volume_id,
                "name": name,
                "tags": tags if tags is not None else ["env:nuvikoz-e2e-disposable"],
                "account_id": account_id,
                "project_id": project_id,
                "status": status,
            }
        }

    # TC-01: Valid exact allowlist permits a simulated deletion.
    def test_tc01_valid_exact_allowlist_permits_simulated_deletion(self) -> None:
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response()))
        manifest = self._create_standard_manifest("droplet-1001")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        report = runner.execute_teardown(auth_context)

        self.assertEqual(report.overall_status, TeardownActionStatus.CLEANUP_INCOMPLETE)
        self.assertEqual(len(report.details), 1)
        self.assertTrue(report.details[0]["success"])
        self.assertEqual(report.details[0]["status"], TeardownActionStatus.DELETION_ACKNOWLEDGED.value)

    # TC-02: Unlisted resource ID is rejected.
    def test_tc02_unlisted_resource_id_is_rejected(self) -> None:
        self.transport.set_route("GET", "/droplets/droplet-unlisted", (200, self._mock_droplet_response("droplet-unlisted")))
        manifest = self._create_standard_manifest("droplet-unlisted")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},  # Only 1001 is authorized; unlisted is absent!
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        report = runner.execute_teardown(auth_context)

        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        self.assertEqual(report.details[0]["status"], TeardownActionStatus.REJECTED_SECURITY_VIOLATION.value)
        self.assertIn("not present in execution authorization allowlist", report.details[0]["message"])

    # TC-03: Missing disposable tag is rejected.
    def test_tc03_missing_disposable_tag_is_rejected(self) -> None:
        # Mock droplet without required tag
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response(tags=["production", "critical"])))
        manifest = self._create_standard_manifest("droplet-1001")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        report = runner.execute_teardown(auth_context)

        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        self.assertEqual(report.details[0]["status"], TeardownActionStatus.REJECTED_SECURITY_VIOLATION.value)
        self.assertIn("ownership verification failed", report.details[0]["message"])

    # TC-04: Wrong account/project is rejected.
    def test_tc04_wrong_account_project_is_rejected(self) -> None:
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response(account_id="acc-foreign-corp")))
        manifest = self._create_standard_manifest("droplet-1001")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        report = runner.execute_teardown(auth_context)

        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        self.assertEqual(report.details[0]["status"], TeardownActionStatus.REJECTED_SECURITY_VIOLATION.value)
        self.assertIn("ownership verification failed", report.details[0]["message"])

    # TC-05: Resource type mismatch is rejected.
    def test_tc05_resource_type_mismatch_is_rejected(self) -> None:
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response()))
        manifest = self._create_standard_manifest("droplet-1001", ResourceType.DROPLET)
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.VOLUME},  # Context only authorizes VOLUME!
            manifest_id=manifest.manifest_id,
        )

        report = runner.execute_teardown(auth_context)

        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        self.assertEqual(report.details[0]["status"], TeardownActionStatus.REJECTED_SECURITY_VIOLATION.value)
        self.assertIn("Resource type 'droplet' not authorized in context", report.details[0]["message"])

    # TC-06: HTTP 429 triggers bounded retry.
    def test_tc06_http_429_triggers_bounded_retry(self) -> None:
        attempts = 0

        def dynamic_handler():
            nonlocal attempts
            attempts += 1
            if attempts <= 2:
                return 429, {"message": "Too Many Requests"}
            return 200, self._mock_droplet_response()

        self.transport.set_route("GET", "/droplets/droplet-1001", dynamic_handler)

        identity = self.adapter.inspect_resource("droplet-1001", ResourceType.DROPLET)
        self.assertEqual(identity.resource_id, "droplet-1001")
        self.assertEqual(identity.status, ResourceStatus.ACTIVE)
        self.assertEqual(attempts, 3)

    # TC-07: HTTP 5xx triggers bounded retry.
    def test_tc07_http_5xx_triggers_bounded_retry(self) -> None:
        attempts = 0

        def dynamic_handler():
            nonlocal attempts
            attempts += 1
            if attempts == 1:
                return 503, {"message": "Service Unavailable"}
            return 200, self._mock_droplet_response()

        self.transport.set_route("GET", "/droplets/droplet-1001", dynamic_handler)

        identity = self.adapter.inspect_resource("droplet-1001", ResourceType.DROPLET)
        self.assertEqual(identity.status, ResourceStatus.ACTIVE)
        self.assertEqual(attempts, 2)

    # TC-08: Network timeout follows the failure policy.
    def test_tc08_network_timeout_follows_failure_policy(self) -> None:
        def timeout_handler():
            raise TimeoutError("Connection timed out to provider API.")

        self.transport.set_route("GET", "/droplets/droplet-1001", timeout_handler)

        with self.assertRaises(TimeoutError) as ctx:
            self.adapter.inspect_resource("droplet-1001", ResourceType.DROPLET)

        self.assertIn("timed out after 3 retries", str(ctx.exception))

    # TC-09: HTTP 404 is handled idempotently.
    def test_tc09_http_404_handled_idempotently(self) -> None:
        self.transport.set_route("GET", "/droplets/droplet-1001", (404, {"message": "Droplet not found"}))
        manifest = self._create_standard_manifest("droplet-1001")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        report = runner.execute_teardown(auth_context)

        self.assertEqual(report.overall_status, TeardownActionStatus.RESOURCE_ABSENCE_CONFIRMED)
        self.assertEqual(report.resources_confirmed_absent, 1)
        self.assertIn("absent prior to deletion request", report.details[0]["message"])

    # TC-10: Restart recovers pending teardown state.
    def test_tc10_restart_recovers_pending_teardown_state(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            manifest_file = Path(tmp_dir) / "disposable-manifest.json"
            manifest = self._create_standard_manifest("droplet-1001")
            manifest.save_atomic(manifest_file)

            # Re-load manifest as if runner crashed and restarted
            recovered_manifest = TeardownManifest.load(manifest_file, current_time=self.fake_now)
            self.assertEqual(recovered_manifest.manifest_id, "manifest-e2e-test-01")
            self.assertEqual(recovered_manifest.state, "PENDING")

            self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response()))
            runner = ExternalTeardownRunner(self.adapter, recovered_manifest, manifest_path=manifest_file, time_provider=lambda: self.fake_now)

            auth_context = ExecutionAuthContext(
                authorized_by="operator-owner",
                authorization_timestamp=self.fake_now.isoformat(),
                dry_run=True,
                allowlisted_resource_ids={"droplet-1001"},
                allowed_resource_types={ResourceType.DROPLET},
                manifest_id=recovered_manifest.manifest_id,
            )

            report = runner.execute_teardown(auth_context)
            self.assertEqual(report.overall_status, TeardownActionStatus.CLEANUP_INCOMPLETE)

            # Assert disk state was atomically updated
            saved_on_disk = TeardownManifest.load(manifest_file, current_time=self.fake_now)
            self.assertEqual(saved_on_disk.state, "INCOMPLETE")
            self.assertEqual(len(saved_on_disk.audit_events), 2)

    # TC-11: Expired deadline triggers teardown eligibility.
    def test_tc11_expired_deadline_triggers_teardown_eligibility(self) -> None:
        # Create manifest with deadline in the past
        past_time = self.fake_now - timedelta(hours=5)
        created_at = (past_time - timedelta(hours=4)).isoformat()
        deadline_at = past_time.isoformat()

        manifest = TeardownManifest(
            manifest_id="manifest-expired-01",
            created_at_utc=created_at,
            deadline_at_utc=deadline_at,
            ownership=self.ownership,
            allowlisted_resources={"droplet-1001": ResourceType.DROPLET.value},
        )

        self.assertTrue(manifest.is_expired(self.fake_now))

        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response()))
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        report = runner.execute_teardown(auth_context)
        self.assertTrue(report.sanitized_summary["is_expired"])
        self.assertEqual(report.overall_status, TeardownActionStatus.CLEANUP_INCOMPLETE)

    # TC-12: Unrelated resources are never deleted.
    def test_tc12_unrelated_resources_never_deleted(self) -> None:
        # Droplet 999999 is in provider but NOT in allowlist
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response("droplet-1001")))
        self.transport.set_route("GET", "/droplets/999999", (200, self._mock_droplet_response("999999", name="unrelated-production-app")))

        manifest = self._create_standard_manifest("droplet-1001")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        runner.execute_teardown(auth_context)

        # Assert no calls were ever made targeting 999999
        called_urls = [call["url"] for call in self.transport.call_history]
        self.assertTrue(all("999999" not in url for url in called_urls))

    # TC-13: Associated resources require separate allowlist checks.
    def test_tc13_associated_resources_require_separate_allowlist_checks(self) -> None:
        # Droplet has an attached volume vol-unregistered
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response(volume_ids=["vol-unregistered"])))
        self.transport.set_route("GET", "/volumes/vol-unregistered", (200, {
            "volume": {
                "id": "vol-unregistered",
                "name": "nuvikoz-e2e-data-vol",
                "tags": ["env:nuvikoz-e2e-disposable"],
                "account_id": "acc-nuvikoz-dev",
                "project_id": "proj-e2e-disposable",
            }
        }))

        # Manifest allowlist contains ONLY droplet-1001, NOT the volume
        manifest = self._create_standard_manifest("droplet-1001")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        report = runner.execute_teardown(auth_context)

        # Runner must fail closed with manual intervention because unapproved associated resource was discovered
        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        self.assertIn("vol-unregistered", report.sanitized_summary["unapproved_associated_orphans"])

    # TC-14: Partial cleanup is reported accurately.
    def test_tc14_partial_cleanup_reported_accurately(self) -> None:
        # Droplet 1 exists; Droplet 2 inspection returns 500
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response("droplet-1001")))
        self.transport.set_route("GET", "/droplets/droplet-1002", (500, {"message": "Internal Server Error"}))

        manifest = TeardownManifest(
            manifest_id="manifest-multi-01",
            created_at_utc=self.fake_now.isoformat(),
            deadline_at_utc=(self.fake_now + timedelta(hours=4)).isoformat(),
            ownership=self.ownership,
            allowlisted_resources={
                "droplet-1001": ResourceType.DROPLET.value,
                "droplet-1002": ResourceType.DROPLET.value,
            },
        )
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001", "droplet-1002"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        with self.assertRaises(RuntimeError):
            runner.execute_teardown(auth_context)

    # TC-15: Secrets are absent from logs and state.
    def test_tc15_secrets_absent_from_logs_and_state(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            manifest_file = Path(tmp_dir) / "audit-manifest.json"
            manifest = self._create_standard_manifest("droplet-1001")
            runner = ExternalTeardownRunner(self.adapter, manifest, manifest_path=manifest_file, time_provider=lambda: self.fake_now)

            self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response()))

            auth_context = ExecutionAuthContext(
                authorized_by="operator-owner",
                authorization_timestamp=self.fake_now.isoformat(),
                dry_run=True,
                allowlisted_resource_ids={"droplet-1001"},
                allowed_resource_types={ResourceType.DROPLET},
                manifest_id=manifest.manifest_id,
            )

            runner.execute_teardown(auth_context)

            serialized_json = manifest_file.read_text(encoding="utf-8")
            forbidden_patterns = ["Bearer", "dop_v1_", "secret", "password", "token", "private_key"]
            for pattern in forbidden_patterns:
                self.assertNotIn(pattern, serialized_json)

    # TC-16: No real network call is possible in the test harness.
    def test_tc16_no_real_network_call_possible_in_test_harness(self) -> None:
        # ExternalTeardownRunner.LIVE_EXECUTION_ENABLED is False by default
        self.assertFalse(ExternalTeardownRunner.LIVE_EXECUTION_ENABLED)

        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response()))

        auth_context_live = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=False,  # Attempting non-dry-run!
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
        )

        with self.assertRaises(TeardownSecurityError) as ctx:
            self.adapter.delete_resource("droplet-1001", ResourceType.DROPLET, auth_context_live)

        self.assertIn("Live provider execution is globally disabled", str(ctx.exception))

    # TC-17: State-file corruption fails closed.
    def test_tc17_state_file_corruption_fails_closed(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            corrupted_file = Path(tmp_dir) / "corrupted-manifest.json"
            # Write malformed JSON
            corrupted_file.write_text("{ incomplete json ...", encoding="utf-8")

            with self.assertRaises(TeardownStateCorruptedError) as ctx:
                TeardownManifest.load(corrupted_file)

            self.assertIn("is corrupted", str(ctx.exception))

    # TC-18: Duplicate execution does not cause unsafe actions.
    def test_tc18_duplicate_execution_does_not_cause_unsafe_actions(self) -> None:
        # First call: droplet active.
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response()))

        manifest = self._create_standard_manifest("droplet-1001")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        # Run 1
        report1 = runner.execute_teardown(auth_context)
        self.assertEqual(report1.overall_status, TeardownActionStatus.CLEANUP_INCOMPLETE)
        self.assertEqual(report1.details[0]["status"], TeardownActionStatus.DELETION_ACKNOWLEDGED.value)

        # Now simulate provider showing resource is absent (404) for Run 2
        self.transport.set_route("GET", "/droplets/droplet-1001", (404, {"message": "Not found"}))

        # Run 2
        report2 = runner.execute_teardown(auth_context)
        self.assertEqual(report2.overall_status, TeardownActionStatus.RESOURCE_ABSENCE_CONFIRMED)
        self.assertEqual(report2.resources_confirmed_absent, 1)

    # TC-19: Unapproved attached volume blocks parent deletion.
    def test_tc19_unapproved_attached_volume_blocks_parent_deletion(self) -> None:
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response(volume_ids=["vol-unapproved-99"])))
        self.transport.set_route("GET", "/volumes/vol-unapproved-99", (200, self._mock_volume_response("vol-unapproved-99")))

        # Manifest allows ONLY droplet-1001, NOT vol-unapproved-99
        manifest = self._create_standard_manifest("droplet-1001")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        report = runner.execute_teardown(auth_context)

        # Critical Correction A: Parent deletion must be halted immediately
        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        self.assertEqual(len(report.details), 1)
        self.assertEqual(report.details[0]["status"], TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED.value)
        self.assertIn("unapproved or mismatched associated resource detected", report.details[0]["message"])
        self.assertIn("vol-unapproved-99", report.sanitized_summary["unapproved_associated_orphans"])

        # Verify adapter.delete_resource was NEVER called (no DELETE or simulated mutation)
        self.assertFalse(any(call["method"] == "DELETE" for call in self.transport.call_history))

    # TC-20: Wrong associated-resource ownership blocks parent deletion.
    def test_tc20_wrong_associated_resource_ownership_blocks_parent_deletion(self) -> None:
        # Both are in manifest allowlist, but volume belongs to a different foreign account
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response(volume_ids=["vol-alien-200"])))
        self.transport.set_route("GET", "/volumes/vol-alien-200", (200, self._mock_volume_response("vol-alien-200", account_id="acc-foreign-corp")))

        created_at = self.fake_now.isoformat()
        deadline = (self.fake_now + timedelta(hours=4)).isoformat()
        manifest = TeardownManifest(
            manifest_id="manifest-tc20",
            created_at_utc=created_at,
            deadline_at_utc=deadline,
            ownership=self.ownership,
            allowlisted_resources={
                "droplet-1001": ResourceType.DROPLET.value,
                "vol-alien-200": ResourceType.VOLUME.value,
            },
        )
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001", "vol-alien-200"},
            allowed_resource_types={ResourceType.DROPLET, ResourceType.VOLUME},
            manifest_id=manifest.manifest_id,
        )

        report = runner.execute_teardown(auth_context)

        # Parent deletion aborted due to ownership failure on associated volume
        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        self.assertIn("vol-alien-200", report.sanitized_summary["unapproved_associated_orphans"])
        # Parent droplet detail must report manual intervention required
        droplet_detail = next(d for d in report.details if d["resource_id"] == "droplet-1001")
        self.assertEqual(droplet_detail["status"], TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED.value)

    # TC-21: Associated resource with incorrect type is rejected.
    def test_tc21_associated_resource_with_incorrect_type_is_rejected(self) -> None:
        # Droplet has attached volume, but manifest registers it as floating_ip
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response(volume_ids=["vol-mismatched-type"])))
        self.transport.set_route("GET", "/volumes/vol-mismatched-type", (200, self._mock_volume_response("vol-mismatched-type")))

        created_at = self.fake_now.isoformat()
        deadline = (self.fake_now + timedelta(hours=4)).isoformat()
        manifest = TeardownManifest(
            manifest_id="manifest-tc21",
            created_at_utc=created_at,
            deadline_at_utc=deadline,
            ownership=self.ownership,
            allowlisted_resources={
                "droplet-1001": ResourceType.DROPLET.value,
                "vol-mismatched-type": ResourceType.FLOATING_IP.value,  # Wrong type registered!
            },
        )
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001", "vol-mismatched-type"},
            allowed_resource_types={ResourceType.DROPLET, ResourceType.FLOATING_IP},
            manifest_id=manifest.manifest_id,
        )

        report = runner.execute_teardown(auth_context)

        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        self.assertIn("vol-mismatched-type", report.sanitized_summary["unapproved_associated_orphans"])

    # TC-22: Provider response missing account or project verification fails closed.
    def test_tc22_provider_response_missing_account_or_project_verification_fails_closed(self) -> None:
        # Clear /account endpoint AND omit payload account_id so account verification is genuinely absent
        self.transport.routes.pop(("GET", "/account"), None)
        resp = self._mock_droplet_response()
        resp["droplet"].pop("account_id", None)
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, resp))

        identity = self.adapter.inspect_resource("droplet-1001", ResourceType.DROPLET)
        self.assertEqual(identity.account_id, "")
        self.assertFalse(identity.matches_ownership(self.ownership))

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
        )

        del_result = self.adapter.delete_resource("droplet-1001", ResourceType.DROPLET, auth_context)
        self.assertEqual(del_result.status, TeardownActionStatus.REJECTED_SECURITY_VIOLATION)
        self.assertIn("ownership verification failed", del_result.message)

    # TC-23: Provider returns different ID from requested ID — reject.
    def test_tc23_provider_returns_different_id_from_requested_id_rejects(self) -> None:
        # Request droplet-1001 but provider returns droplet with id droplet-malicious-redirect
        resp = self._mock_droplet_response(droplet_id="droplet-malicious-redirect")
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, resp))

        with self.assertRaises(TeardownSecurityError) as ctx:
            self.adapter.inspect_resource("droplet-1001", ResourceType.DROPLET)

        self.assertIn("does not match requested ID", str(ctx.exception))

    # TC-24: Deletion acknowledgement without confirmed absence remains incomplete.
    def test_tc24_deletion_acknowledgement_without_confirmed_absence_remains_incomplete(self) -> None:
        # Inspect returns active; deletion returns acknowledged (dry-run simulation)
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response()))

        manifest = self._create_standard_manifest("droplet-1001")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        report = runner.execute_teardown(auth_context)

        # Deletion acknowledged alone cannot mark manifest completed; it remains CLEANUP_INCOMPLETE
        self.assertEqual(report.overall_status, TeardownActionStatus.CLEANUP_INCOMPLETE)
        self.assertEqual(report.resources_confirmed_absent, 0)
        self.assertEqual(report.cleanup_incomplete_count, 1)
        self.assertEqual(manifest.state, "INCOMPLETE")

    # TC-25: Repeated execution after partial cleanup safely resumes.
    def test_tc25_repeated_execution_after_partial_cleanup_safely_resumes(self) -> None:
        # Pass 1: droplet-1001 is already absent (404), droplet-1002 is active
        self.transport.set_route("GET", "/droplets/droplet-1001", (404, {"message": "Not found"}))
        self.transport.set_route("GET", "/droplets/droplet-1002", (200, self._mock_droplet_response("droplet-1002")))

        created_at = self.fake_now.isoformat()
        deadline = (self.fake_now + timedelta(hours=4)).isoformat()
        manifest = TeardownManifest(
            manifest_id="manifest-tc25",
            created_at_utc=created_at,
            deadline_at_utc=deadline,
            ownership=self.ownership,
            allowlisted_resources={
                "droplet-1001": ResourceType.DROPLET.value,
                "droplet-1002": ResourceType.DROPLET.value,
            },
        )
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001", "droplet-1002"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        # First run: 1 absent, 1 acknowledged (incomplete)
        report1 = runner.execute_teardown(auth_context)
        self.assertEqual(report1.overall_status, TeardownActionStatus.CLEANUP_INCOMPLETE)
        self.assertEqual(report1.resources_confirmed_absent, 1)

        # Pass 2: Now droplet-1002 is also 404 absent
        self.transport.set_route("GET", "/droplets/droplet-1002", (404, {"message": "Not found"}))

        report2 = runner.execute_teardown(auth_context)
        self.assertEqual(report2.overall_status, TeardownActionStatus.RESOURCE_ABSENCE_CONFIRMED)
        self.assertEqual(report2.resources_confirmed_absent, 2)
        self.assertEqual(manifest.state, "COMPLETED")

    # TC-26: Invalid or manipulated deadline is rejected.
    def test_tc26_invalid_or_manipulated_deadline_is_rejected(self) -> None:
        created_at = self.fake_now.isoformat()

        # 1. Window exceeds 4 hours (e.g. 5 hours) -> TeardownSecurityError
        exceeded_deadline = (self.fake_now + timedelta(hours=5)).isoformat()
        manifest_exceeded = TeardownManifest(
            manifest_id="manifest-invalid-deadline",
            created_at_utc=created_at,
            deadline_at_utc=exceeded_deadline,
            ownership=self.ownership,
            allowlisted_resources={"droplet-1001": ResourceType.DROPLET.value},
        )
        with self.assertRaises(TeardownSecurityError) as ctx:
            manifest_exceeded.validate_state(self.fake_now)
        self.assertIn("exceeds maximum allowed 4-hour window", str(ctx.exception))

        # 2. Deadline before creation -> TeardownStateCorruptedError
        backward_deadline = (self.fake_now - timedelta(hours=1)).isoformat()
        manifest_backward = TeardownManifest(
            manifest_id="manifest-backward",
            created_at_utc=created_at,
            deadline_at_utc=backward_deadline,
            ownership=self.ownership,
            allowlisted_resources={"droplet-1001": ResourceType.DROPLET.value},
        )
        with self.assertRaises(TeardownStateCorruptedError) as ctx:
            manifest_backward.validate_state(self.fake_now)
        self.assertIn("strictly after creation", str(ctx.exception))

        # 3. Future-dated creation timestamp -> TeardownStateCorruptedError
        future_created = (self.fake_now + timedelta(hours=2)).isoformat()
        manifest_future = TeardownManifest(
            manifest_id="manifest-future",
            created_at_utc=future_created,
            deadline_at_utc=(self.fake_now + timedelta(hours=4)).isoformat(),
            ownership=self.ownership,
            allowlisted_resources={"droplet-1001": ResourceType.DROPLET.value},
        )
        with self.assertRaises(TeardownStateCorruptedError) as ctx:
            manifest_future.validate_state(self.fake_now)
        self.assertIn("future-dated", str(ctx.exception))

    # TC-27: Non-dry-run execution cannot reach real network deletion.
    def test_tc27_non_dry_run_execution_cannot_reach_real_network_deletion(self) -> None:
        self.assertFalse(ExternalTeardownRunner.LIVE_EXECUTION_ENABLED)
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response()))

        auth_context_live = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=False,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
        )

        with self.assertRaises(TeardownSecurityError) as ctx:
            self.adapter.delete_resource("droplet-1001", ResourceType.DROPLET, auth_context_live)

        self.assertIn("Live provider execution is globally disabled", str(ctx.exception))
        # Ensure transport never dispatched a DELETE call
        self.assertFalse(any(call["method"] == "DELETE" for call in self.transport.call_history))

    # TC-28: A corrupted manifest cannot authorize any destructive action.
    def test_tc28_corrupted_manifest_cannot_authorize_any_destructive_action(self) -> None:
        # Missing required 'ownership' dictionary
        incomplete_dict = {
            "manifest_id": "manifest-broken",
            "created_at_utc": self.fake_now.isoformat(),
            "deadline_at_utc": (self.fake_now + timedelta(hours=4)).isoformat(),
            "allowlisted_resources": {"droplet-1001": "droplet"},
        }
        with self.assertRaises(TeardownStateCorruptedError) as ctx:
            TeardownManifest.from_dict(incomplete_dict, current_time=self.fake_now)
        self.assertIn("missing required keys", str(ctx.exception))

        # Missing 'allowlisted_resources'
        missing_allowlist = {
            "manifest_id": "manifest-broken-2",
            "created_at_utc": self.fake_now.isoformat(),
            "deadline_at_utc": (self.fake_now + timedelta(hours=4)).isoformat(),
            "ownership": {
                "expected_account_id": "acc-1",
                "required_tag": "tag-1",
                "expected_name_prefix": "prefix-1",
            },
        }
        with self.assertRaises(TeardownStateCorruptedError) as ctx:
            TeardownManifest.from_dict(missing_allowlist, current_time=self.fake_now)
        self.assertIn("missing required keys", str(ctx.exception))

    # TC-29: Authorized parent deletion does not implicitly delete attached storage.
    def test_tc29_authorized_parent_deletion_does_not_implicitly_delete_attached_storage(self) -> None:
        # Both parent droplet and volume are in manifest allowlist
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response(volume_ids=["vol-2001"])))
        self.transport.set_route("GET", "/volumes/vol-2001", (200, self._mock_volume_response("vol-2001")))

        created_at = self.fake_now.isoformat()
        deadline = (self.fake_now + timedelta(hours=4)).isoformat()
        manifest = TeardownManifest(
            manifest_id="manifest-tc29",
            created_at_utc=created_at,
            deadline_at_utc=deadline,
            ownership=self.ownership,
            allowlisted_resources={
                "droplet-1001": ResourceType.DROPLET.value,
                "vol-2001": ResourceType.VOLUME.value,
            },
        )
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001", "vol-2001"},
            allowed_resource_types={ResourceType.DROPLET, ResourceType.VOLUME},
            manifest_id=manifest.manifest_id,
        )

        report = runner.execute_teardown(auth_context)

        # Both resources evaluated independently; volume is not implicitly marked absent when droplet is processed
        self.assertEqual(len(report.details), 2)
        droplet_res = next(d for d in report.details if d["resource_id"] == "droplet-1001")
        vol_res = next(d for d in report.details if d["resource_id"] == "vol-2001")

        self.assertIn("vol-2001", droplet_res["reconciled_associated_resources"])
        self.assertEqual(vol_res["status"], TeardownActionStatus.DELETION_ACKNOWLEDGED.value)
        # Because both are simulated dry-run (acknowledged), absence is not confirmed yet
        self.assertEqual(report.overall_status, TeardownActionStatus.CLEANUP_INCOMPLETE)

    # TC-30: Reconciliation requires independently verified resource absence.
    def test_tc30_reconciliation_requires_independently_verified_resource_absence(self) -> None:
        created_at = self.fake_now.isoformat()
        deadline = (self.fake_now + timedelta(hours=4)).isoformat()
        manifest = TeardownManifest(
            manifest_id="manifest-tc30",
            created_at_utc=created_at,
            deadline_at_utc=deadline,
            ownership=self.ownership,
            allowlisted_resources={
                "droplet-1001": ResourceType.DROPLET.value,
                "vol-2001": ResourceType.VOLUME.value,
            },
        )
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001", "vol-2001"},
            allowed_resource_types={ResourceType.DROPLET, ResourceType.VOLUME},
            manifest_id=manifest.manifest_id,
        )

        # Phase 1: Droplet is 404 absent, but Volume is still 200 active
        self.transport.set_route("GET", "/droplets/droplet-1001", (404, {"message": "Not found"}))
        self.transport.set_route("GET", "/volumes/vol-2001", (200, self._mock_volume_response("vol-2001")))

        report1 = runner.execute_teardown(auth_context)
        self.assertEqual(report1.overall_status, TeardownActionStatus.CLEANUP_INCOMPLETE)
        self.assertEqual(report1.resources_confirmed_absent, 1)
        self.assertEqual(report1.cleanup_incomplete_count, 1)
        self.assertEqual(manifest.state, "INCOMPLETE")

        # Phase 2: Now Volume is ALSO independently verified absent (404)
        self.transport.set_route("GET", "/volumes/vol-2001", (404, {"message": "Not found"}))

        report2 = runner.execute_teardown(auth_context)
        self.assertEqual(report2.overall_status, TeardownActionStatus.RESOURCE_ABSENCE_CONFIRMED)
        self.assertEqual(report2.resources_confirmed_absent, 2)
        self.assertEqual(report2.cleanup_incomplete_count, 0)
        self.assertEqual(manifest.state, "COMPLETED")

    # TC-31: UNKNOWN resource status refuses deletion.
    def test_tc31_unknown_resource_status_refuses_deletion(self) -> None:
        resp_unknown = self._mock_droplet_response()
        resp_unknown["droplet"]["status"] = "weird-unrecognized-state"
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, resp_unknown))

        identity = self.adapter.inspect_resource("droplet-1001", ResourceType.DROPLET)
        self.assertEqual(identity.status, ResourceStatus.UNKNOWN)
        self.assertFalse(identity.matches_ownership(self.ownership))

        manifest = self._create_standard_manifest("droplet-1001")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)
        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        report = runner.execute_teardown(auth_context)
        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        self.assertEqual(report.details[0]["status"], TeardownActionStatus.REJECTED_SECURITY_VIOLATION.value)
        self.assertIn("UNKNOWN", report.details[0]["message"])
        self.assertFalse(any(call["method"] == "DELETE" for call in self.transport.call_history))

    # TC-32: Unsupported DELETING state cannot authorize a second deletion.
    def test_tc32_unsupported_deleting_state_cannot_authorize_second_deletion(self) -> None:
        resp_deleting = self._mock_droplet_response()
        resp_deleting["droplet"]["status"] = "deleting"
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, resp_deleting))

        identity = self.adapter.inspect_resource("droplet-1001", ResourceType.DROPLET)
        self.assertEqual(identity.status, ResourceStatus.DELETING)
        self.assertFalse(identity.matches_ownership(self.ownership))

        manifest = self._create_standard_manifest("droplet-1001")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)
        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        report = runner.execute_teardown(auth_context)
        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        self.assertEqual(report.details[0]["status"], TeardownActionStatus.REJECTED_SECURITY_VIOLATION.value)
        self.assertIn("DELETING", report.details[0]["message"])
        self.assertFalse(any(call["method"] == "DELETE" for call in self.transport.call_history))

    # TC-33: Missing provider status fails closed.
    def test_tc33_missing_provider_status_fails_closed(self) -> None:
        # Authentic DO volume response omits status; droplet response missing status also fails closed
        resp_droplet = self._mock_droplet_response()
        resp_droplet["droplet"].pop("status", None)
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, resp_droplet))

        ident_droplet = self.adapter.inspect_resource("droplet-1001", ResourceType.DROPLET)
        self.assertEqual(ident_droplet.status, ResourceStatus.UNKNOWN)
        self.assertFalse(ident_droplet.matches_ownership(self.ownership))

        # Official DO volume payload without 'status' field MUST be UNKNOWN (not inferred active)
        self.transport.set_route("GET", "/volumes/vol-2001", (200, {
            "volume": {
                "id": "vol-2001",
                "name": "nuvikoz-e2e-data-vol",
                "tags": ["env:nuvikoz-e2e-disposable"],
                "droplet_ids": [],
            }
        }))
        ident_vol = self.adapter.inspect_resource("vol-2001", ResourceType.VOLUME)
        self.assertEqual(ident_vol.status, ResourceStatus.UNKNOWN)
        self.assertFalse(ident_vol.matches_ownership(self.ownership))

        manifest = self._create_standard_manifest("droplet-1001")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)
        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )
        report = runner.execute_teardown(auth_context)
        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        self.assertFalse(any(call["method"] == "DELETE" for call in self.transport.call_history))

    # TC-34: DNS record without domain identity is rejected.
    def test_tc34_dns_record_without_domain_identity_is_rejected(self) -> None:
        # Calling inspect_resource for DNS_RECORD with no domain fails closed
        with self.assertRaises(TeardownSecurityError) as ctx:
            self.adapter.inspect_resource("3352896", ResourceType.DNS_RECORD)
        self.assertIn("domain identity is required", str(ctx.exception))

        # Traversal and ambiguous identifiers are rejected cleanly
        for bad_domain in ("", "../evil.com", "example.com/foo", "a%2fb.com", "bad?q=1"):
            with self.assertRaises(TeardownSecurityError):
                self.adapter.build_dns_record_path(bad_domain, "3352896")

        for bad_id in ("", "abc", "3352896/../../droplets/1", "3352896%2f1", "-5"):
            with self.assertRaises(TeardownSecurityError):
                self.adapter.build_dns_record_path("e2e.nuvikoz-test.invalid", bad_id)

        # Manifest execution for DNS record without domain binding rejects without DELETE calls
        manifest = self._create_standard_manifest("3352896", ResourceType.DNS_RECORD)
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)
        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"3352896"},
            allowed_resource_types={ResourceType.DNS_RECORD},
            manifest_id=manifest.manifest_id,
        )
        report = runner.execute_teardown(auth_context)
        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        self.assertEqual(report.details[0]["status"], TeardownActionStatus.REJECTED_SECURITY_VIOLATION.value)
        self.assertFalse(any(call["method"] == "DELETE" for call in self.transport.call_history))

    # TC-35: DNS route construction uses validated domain and record identity.
    def test_tc35_dns_route_construction_uses_validated_domain_and_record_identity(self) -> None:
        safe_path = self.adapter.build_dns_record_path("e2e.nuvikoz-test.invalid", "3352896")
        self.assertEqual(safe_path, "/domains/e2e.nuvikoz-test.invalid/records/3352896")

        self.transport.set_route("GET", safe_path, (200, {
            "domain_record": {
                "id": 3352896,
                "type": "A",
                "name": "nuvikoz-e2e-host",
                "data": "198.51.100.10",
                "ttl": 1800,
            }
        }))

        ident = self.adapter.inspect_resource("3352896", ResourceType.DNS_RECORD, domain_name="e2e.nuvikoz-test.invalid")
        self.assertEqual(ident.resource_id, "3352896")
        self.assertEqual(ident.name, "nuvikoz-e2e-host")
        self.assertEqual(ident.project_id, "")
        self.assertEqual(ident.status, ResourceStatus.UNKNOWN)
        self.assertTrue(any(c["url"] == "https://api.digitalocean.com/v2/domains/e2e.nuvikoz-test.invalid/records/3352896" for c in self.transport.call_history))

        # Automated DNS deletion remains unsupported fail-closed even if record name starts with prefix
        manifest = self._create_standard_manifest("3352896", ResourceType.DNS_RECORD)
        manifest.dns_record_domains = {"3352896": "e2e.nuvikoz-test.invalid"}
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)
        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"3352896"},
            allowed_resource_types={ResourceType.DNS_RECORD},
            manifest_id=manifest.manifest_id,
        )
        report = runner.execute_teardown(auth_context)
        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        self.assertEqual(report.details[0]["status"], TeardownActionStatus.REJECTED_SECURITY_VIOLATION.value)
        self.assertFalse(any(call["method"] == "DELETE" for call in self.transport.call_history))

    # TC-36: Missing account/project ownership evidence is rejected.
    def test_tc36_missing_account_or_project_ownership_evidence_is_rejected(self) -> None:
        # Pure official DO droplet response (NO test-only account_id or project_id fields)
        official_droplet_payload = {
            "droplet": {
                "id": 1001,
                "name": "nuvikoz-e2e-vps",
                "status": "active",
                "volume_ids": [],
                "tags": ["env:nuvikoz-e2e-disposable"],
            }
        }
        self.transport.set_route("GET", "/droplets/1001", (200, official_droplet_payload))

        # 1. Missing /account proof fails closed even when project URN is registered
        self.transport.routes.pop(("GET", "/account"), None)
        self.transport.set_route("GET", "/projects/proj-e2e-disposable/resources", (200, {
            "resources": [{"urn": "do:droplet:1001", "status": "ok"}]
        }))
        ident_no_acct = self.adapter.inspect_resource("1001", ResourceType.DROPLET)
        self.assertEqual(ident_no_acct.account_id, "")
        self.assertFalse(ident_no_acct.matches_ownership(self.ownership))

        # 2. Active /account present, but droplet URN missing from /projects/{id}/resources fails closed
        self._register_default_ownership_routes()
        self.transport.set_route("GET", "/projects/proj-e2e-disposable/resources", (200, {"resources": []}))
        ident_no_proj = self.adapter.inspect_resource("1001", ResourceType.DROPLET)
        self.assertEqual(ident_no_proj.account_id, "acc-nuvikoz-dev")
        self.assertEqual(ident_no_proj.project_id, "")
        self.assertFalse(ident_no_proj.matches_ownership(self.ownership))

        # 3. With BOTH /account and /projects/{id}/resources proving ownership, official payload succeeds dry-run
        self.transport.set_route("GET", "/projects/proj-e2e-disposable/resources", (200, {
            "resources": [{"urn": "do:droplet:1001", "status": "ok"}]
        }))
        ident_valid = self.adapter.inspect_resource("1001", ResourceType.DROPLET)
        self.assertTrue(ident_valid.matches_ownership(self.ownership))

    # TC-37: Client-provided tags alone cannot establish ownership.
    def test_tc37_client_provided_tags_alone_cannot_establish_ownership(self) -> None:
        # Droplet has matching tag and name prefix, and active status, but lacks account & project proof
        self.transport.routes.pop(("GET", "/account"), None)
        self.transport.routes.pop(("GET", "/projects/proj-e2e-disposable/resources"), None)
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, {
            "droplet": {
                "id": "droplet-1001",
                "name": "nuvikoz-e2e-vps",
                "status": "active",
                "volume_ids": [],
                "tags": ["env:nuvikoz-e2e-disposable"],
            }
        }))

        manifest = self._create_standard_manifest("droplet-1001")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)
        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        report = runner.execute_teardown(auth_context)
        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        self.assertEqual(report.details[0]["status"], TeardownActionStatus.REJECTED_SECURITY_VIOLATION.value)
        self.assertFalse(any(call["method"] == "DELETE" for call in self.transport.call_history))

    # TC-38: Invalid, expired or future authorization timestamp is rejected.
    def test_tc38_invalid_expired_or_future_authorization_timestamp_is_rejected(self) -> None:
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response()))
        manifest = self._create_standard_manifest("droplet-1001")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        bad_timestamps = [
            "not-a-valid-timestamp",
            "2026-11-22T12:00:00",  # Missing timezone offset
            (self.fake_now + timedelta(minutes=5)).isoformat(),  # Future outside 60s skew
            (self.fake_now - timedelta(hours=2)).isoformat(),    # Stale/expired beyond 3600s TTL
        ]

        for bad_ts in bad_timestamps:
            auth_ctx = ExecutionAuthContext(
                authorized_by="operator-owner",
                authorization_timestamp=bad_ts,
                dry_run=True,
                allowlisted_resource_ids={"droplet-1001"},
                allowed_resource_types={ResourceType.DROPLET},
                manifest_id=manifest.manifest_id,
            )
            with self.assertRaises(TeardownSecurityError):
                runner.execute_teardown(auth_ctx)

        # Empty authorized_by must also fail closed
        blank_actor_ctx = ExecutionAuthContext(
            authorized_by="   ",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )
        with self.assertRaises(TeardownSecurityError):
            runner.execute_teardown(blank_actor_ctx)

        self.assertFalse(any(call["method"] == "DELETE" for call in self.transport.call_history))

    # TC-39: Authorization for a different manifest cannot be reused.
    def test_tc39_authorization_for_different_manifest_cannot_be_reused(self) -> None:
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response()))
        manifest = self._create_standard_manifest("droplet-1001")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        # Wrong manifest_id
        other_manifest_ctx = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id="manifest-unrelated-999",
        )
        with self.assertRaises(TeardownSecurityError) as ctx1:
            runner.execute_teardown(other_manifest_ctx)
        self.assertIn("cannot be reused", str(ctx1.exception))
        self.assertEqual(manifest.state, "PENDING")

        # Unbound manifest_id (None)
        unbound_ctx = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=None,
        )
        with self.assertRaises(TeardownSecurityError) as ctx2:
            runner.execute_teardown(unbound_ctx)
        self.assertIn("must be explicitly bound to a manifest_id", str(ctx2.exception))

        # Ambiguous extra ID in auth_context blocks execution
        ambiguous_ctx = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001", "droplet-unexpected"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )
        report = runner.execute_teardown(ambiguous_ctx)
        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        self.assertEqual(report.details[0]["status"], TeardownActionStatus.REJECTED_SECURITY_VIOLATION.value)
        self.assertIn("unexpected resource IDs", report.details[0]["message"])

    # TC-40: Associated-resource inspection or enumeration failure blocks parent deletion.
    def test_tc40_associated_resource_inspection_or_enumeration_failure_blocks_parent_deletion(self) -> None:
        # Case A: Parent droplet enumerates attached vol-2001 whose inspection fails -> parent halts immediately
        vol_attempts = 0

        def flaky_vol_handler():
            nonlocal vol_attempts
            vol_attempts += 1
            if vol_attempts <= 4:  # Exhaust initial attempt + 3 retries during parent enumeration
                return 500, {"message": "Storage API unavailable"}
            return 200, self._mock_volume_response("vol-2001")

        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response(volume_ids=["vol-2001"])))
        self.transport.set_route("GET", "/volumes/vol-2001", flaky_vol_handler)

        created_at = self.fake_now.isoformat()
        deadline = (self.fake_now + timedelta(hours=4)).isoformat()
        manifest = TeardownManifest(
            manifest_id="manifest-tc40",
            created_at_utc=created_at,
            deadline_at_utc=deadline,
            ownership=self.ownership,
            allowlisted_resources={
                "droplet-1001": ResourceType.DROPLET.value,
                "vol-2001": ResourceType.VOLUME.value,
            },
        )
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)
        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001", "vol-2001"},
            allowed_resource_types={ResourceType.DROPLET, ResourceType.VOLUME},
            manifest_id=manifest.manifest_id,
        )

        # Associated vol-2001 failure produces UNKNOWN identity -> halts parent droplet-1001 with MANUAL_INTERVENTION_REQUIRED
        report = runner.execute_teardown(auth_context)
        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        droplet_detail = next(d for d in report.details if d["resource_id"] == "droplet-1001")
        self.assertEqual(droplet_detail["status"], TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED.value)
        self.assertIn("vol-2001", report.sanitized_summary["unapproved_associated_orphans"])
        self.assertFalse(any(call["method"] == "DELETE" for call in self.transport.call_history))

        # Case B: Malformed non-list volume_ids fails enumeration before any deletion
        bad_payload = self._mock_droplet_response()
        bad_payload["droplet"]["volume_ids"] = "vol-2001"  # Malformed string instead of list
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, bad_payload))
        with self.assertRaises(TeardownSecurityError):
            runner.execute_teardown(auth_context)
        self.assertFalse(any(call["method"] == "DELETE" for call in self.transport.call_history))

    # TC-41: Manifest TZ, state, creation mutation, and ownership conflict protections.
    def test_tc41_manifest_timezone_state_sliding_window_and_ownership_conflicts_fail_closed(self) -> None:
        # 1. Missing timezone offset in manifest timestamp
        bad_tz = self._create_standard_manifest("droplet-1001")
        bad_tz.created_at_utc = "2026-11-22T12:00:00"  # naive timestamp
        with self.assertRaises(TeardownStateCorruptedError):
            bad_tz.validate_state(self.fake_now)

        # 2. Unexpected state value
        bad_state = self._create_standard_manifest("droplet-1001")
        bad_state.state = "ARBITRARY_FORGED_STATE"
        with self.assertRaises(TeardownStateCorruptedError):
            bad_state.validate_state(self.fake_now)

        # 3. Manifest creation timestamp bumped forward past earlier audit event
        slid_manifest = self._create_standard_manifest("droplet-1001")
        slid_manifest.audit_events.append({
            "event": "TEARDOWN_STARTED",
            "timestamp": (self.fake_now - timedelta(hours=1)).isoformat(),
            "details": {},
        })
        slid_manifest.created_at_utc = self.fake_now.isoformat()
        with self.assertRaises(TeardownStateCorruptedError):
            slid_manifest.validate_state(self.fake_now)

        # 4. Ownership conflict between manifest and adapter fails closed
        conflicting_ownership = OwnershipContract(
            expected_account_id="acc-other-tenant",
            expected_project_id="proj-e2e-disposable",
            required_tag="env:nuvikoz-e2e-disposable",
            expected_name_prefix="nuvikoz-e2e-",
        )
        conflict_manifest = TeardownManifest(
            manifest_id="manifest-conflict",
            created_at_utc=self.fake_now.isoformat(),
            deadline_at_utc=(self.fake_now + timedelta(hours=4)).isoformat(),
            ownership=conflicting_ownership,
            allowlisted_resources={"droplet-1001": ResourceType.DROPLET.value},
        )
        runner = ExternalTeardownRunner(self.adapter, conflict_manifest, time_provider=lambda: self.fake_now)
        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=conflict_manifest.manifest_id,
        )
        with self.assertRaises(TeardownSecurityError) as ctx:
            runner.execute_teardown(auth_context)
        self.assertIn("conflicts with provider adapter", str(ctx.exception))

    # TC-42: Official Reserved IP endpoint validation and non-synthesis.
    def test_tc42_reserved_ip_validation_and_non_synthesis(self) -> None:
        # Invalid IP rejected prior to HTTP call
        with self.assertRaises(TeardownSecurityError):
            self.adapter.inspect_resource("not-an-ip/../droplets/1", ResourceType.RESERVED_IP)

        # Authentic DO /v2/reserved_ips/{ip} omits name, tags, and status -> MUST NOT synthesize
        self.transport.set_route("GET", "/projects/proj-e2e-disposable/resources", (200, {
            "resources": [{"urn": "do:floatingip:198.51.100.42", "status": "ok"}]
        }))
        self.transport.set_route("GET", "/reserved_ips/198.51.100.42", (200, {
            "reserved_ip": {
                "ip": "198.51.100.42",
                "locked": False,
                "droplet": None,
                "project_id": "proj-e2e-disposable",
            }
        }))

        ident = self.adapter.inspect_resource("198.51.100.42", ResourceType.RESERVED_IP)
        self.assertEqual(ident.name, "")
        self.assertEqual(ident.tags, [])
        self.assertEqual(ident.status, ResourceStatus.UNKNOWN)
        self.assertEqual(ident.project_id, "proj-e2e-disposable")
        self.assertFalse(ident.matches_ownership(self.ownership))

    # TC-43: Resource remaining ACTIVE after deletion acknowledgement stays INCOMPLETE.
    def test_tc43_resource_remaining_active_after_delete_acknowledgment_remains_incomplete(self) -> None:
        self.transport.set_route("GET", "/droplets/droplet-1001", (200, self._mock_droplet_response()))
        manifest = self._create_standard_manifest("droplet-1001")
        runner = ExternalTeardownRunner(self.adapter, manifest, time_provider=lambda: self.fake_now)

        auth_context = ExecutionAuthContext(
            authorized_by="operator-owner",
            authorization_timestamp=self.fake_now.isoformat(),
            dry_run=True,
            allowlisted_resource_ids={"droplet-1001"},
            allowed_resource_types={ResourceType.DROPLET},
            manifest_id=manifest.manifest_id,
        )

        # Two consecutive polls where droplet remains 200 ACTIVE after deletion acknowledgement
        report1 = runner.execute_teardown(auth_context)
        self.assertEqual(report1.overall_status, TeardownActionStatus.CLEANUP_INCOMPLETE)
        self.assertEqual(manifest.state, "INCOMPLETE")

        report2 = runner.execute_teardown(auth_context)
        self.assertEqual(report2.overall_status, TeardownActionStatus.CLEANUP_INCOMPLETE)
        self.assertEqual(report2.resources_confirmed_absent, 0)
        self.assertEqual(manifest.state, "INCOMPLETE")

        # Only after independent 404 confirmation does manifest become COMPLETED
        self.transport.set_route("GET", "/droplets/droplet-1001", (404, {"message": "Not found"}))
        report3 = runner.execute_teardown(auth_context)
        self.assertEqual(report3.overall_status, TeardownActionStatus.RESOURCE_ABSENCE_CONFIRMED)
        self.assertEqual(manifest.state, "COMPLETED")

    # TC-44: Team account context and paginated project resources URN verification.
    def test_tc44_team_account_context_and_paginated_project_resources(self) -> None:
        self.transport.set_route("GET", "/account", (200, {
            "account": {
                "uuid": "user-uuid-ignored-in-team-context",
                "status": "active",
                "team": {"uuid": "acc-nuvikoz-dev", "name": "NUVIKOZ E2E Team"},
            }
        }))
        self.transport.set_route("GET", "/projects/proj-e2e-disposable/resources", (200, {
            "resources": [{"urn": "do:droplet:other-resource", "status": "ok"}],
            "links": {
                "pages": {
                    "next": "https://api.digitalocean.com/v2/projects/proj-e2e-disposable/resources?page=2"
                }
            },
        }))
        self.transport.set_route("GET", "/projects/proj-e2e-disposable/resources?page=2", (200, {
            "resources": [{"urn": "do:droplet:1001", "status": "ok"}],
            "links": {"pages": {}},
        }))
        self.transport.set_route("GET", "/droplets/1001", (200, {
            "droplet": {
                "id": 1001,
                "name": "nuvikoz-e2e-vps",
                "status": "active",
                "volume_ids": [],
                "tags": ["env:nuvikoz-e2e-disposable"],
            }
        }))

        ident = self.adapter.inspect_resource("1001", ResourceType.DROPLET)
        self.assertEqual(ident.account_id, "acc-nuvikoz-dev")
        self.assertEqual(ident.project_id, "proj-e2e-disposable")
        self.assertTrue(ident.matches_ownership(self.ownership))


if __name__ == "__main__":
    unittest.main()
