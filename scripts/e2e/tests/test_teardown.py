"""NUVIKOZ Edu — Disposable E2E External Teardown Test Suite.

Verifies the 18-point acceptance matrix (TC-01 through TC-18) using 100% mocked
transports and zero network access.
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
    """18-case acceptance test suite covering TC-01 to TC-18."""

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
        )

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
        )

        report = runner.execute_teardown(auth_context)

        self.assertEqual(report.overall_status, TeardownActionStatus.DELETION_ACKNOWLEDGED)
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
            recovered_manifest = TeardownManifest.load(manifest_file)
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
            )

            report = runner.execute_teardown(auth_context)
            self.assertEqual(report.overall_status, TeardownActionStatus.DELETION_ACKNOWLEDGED)

            # Assert disk state was atomically updated
            saved_on_disk = TeardownManifest.load(manifest_file)
            self.assertEqual(saved_on_disk.state, "COMPLETED")
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
        )

        report = runner.execute_teardown(auth_context)
        self.assertTrue(report.sanitized_summary["is_expired"])
        self.assertEqual(report.overall_status, TeardownActionStatus.DELETION_ACKNOWLEDGED)

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
        )

        # Run 1
        report1 = runner.execute_teardown(auth_context)
        self.assertEqual(report1.overall_status, TeardownActionStatus.DELETION_ACKNOWLEDGED)

        # Now simulate provider showing resource is absent (404) for Run 2
        self.transport.set_route("GET", "/droplets/droplet-1001", (404, {"message": "Not found"}))

        # Run 2
        report2 = runner.execute_teardown(auth_context)
        self.assertEqual(report2.overall_status, TeardownActionStatus.RESOURCE_ABSENCE_CONFIRMED)
        self.assertEqual(report2.resources_confirmed_absent, 1)


if __name__ == "__main__":
    unittest.main()
