"""NUVIKOZ Edu — Disposable E2E External Teardown Test Suite.

Verifies the 30-point acceptance matrix (TC-01 through TC-30) using 100% mocked
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
    """30-case acceptance test suite covering TC-01 to TC-30."""

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
        )

        report = runner.execute_teardown(auth_context)

        self.assertEqual(report.overall_status, TeardownActionStatus.MANUAL_INTERVENTION_REQUIRED)
        self.assertIn("vol-mismatched-type", report.sanitized_summary["unapproved_associated_orphans"])

    # TC-22: Provider response missing account or project verification fails closed.
    def test_tc22_provider_response_missing_account_or_project_verification_fails_closed(self) -> None:
        # Mock droplet payload missing account_id (not provided by API)
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


if __name__ == "__main__":
    unittest.main()
