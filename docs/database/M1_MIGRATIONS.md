# M1 initial migrations

Initial identity migration creates users,schools,memberships,membership_permissions,audit_events,idempotency_records,personal_access_tokens and sessions. Framework cache/jobs migrations are platform infrastructure,not school-domain tables. No academic/fee/file storage tables are prematurely implemented.

ULID identities;school+id unique membership key;school-scoped login identifier uniqueness;restrictive foreign keys;composite school/membership permissions and idempotency pointer references. Platform authority is a non-mass-assignable global user flag;school memberships admit only five school role types. Thus six initial role types remain,without PSA membership impersonation.

This is an initial subset of the100-table logical design,not a silent replacement. Membership login_identifier is the current assigned-ID lookup;verified global contact identifiers,staff/student/guardian profiles and time-bounded link tables remain planned. Staff provisioning currently accepts onlyteacher/principal;student/parent account creation is deferred until profile/link validation exists.

Audit model rejects update/delete;this is app-level immutability,not a claim of DB-admin-proof storage. Provisioning audit and idempotency pointer persist in the same transaction. No plaintext provisional secret in idempotency records. Login token mint and credential rotation use the user lock;credential change revokes tokens atomically and emits scoped audit events.

SQLite test migrations and composite FK negative cases executed locally. LocalMariaDBserver cannot bind a socket;CI applies the suite plus migrate:fresh→rollback→migrate on a disposable MariaDB service. Never execute those commands on real school data. ReportCIresult before claimingMariaDBvalidation.

## Security review migration

`2026_10_28_000001_protect_audit_history.php` adds BEFORE UPDATE/DELETE triggers for audit rows on MariaDB and SQLite. Query-builder bypasses now fail in tests. Down migration removes the triggers; initial identity migrations remain unchanged. The corrected schema and full rollback/remigration passed in isolated MariaDB CI at `5ad94647949089dfe1360470ba1c770ff87d56c5`.

Future runtime credentials must lack DROP/ALTER/TRIGGER privileges; a separate migration principal owns schema changes. A database administrator can still drop a trigger or truncate/drop a table, so administrator-proof/tamper-evident offsite history is not claimed. Never run the CI reset commands on real data.

## AUTH-04 additive migration

`2026_10_28_000003_create_account_activations.php` adds `users.activation_required` (boolean, default false for existing accounts) and `account_activations`. Proof rows contain ULID, school/user and same-school target/issuer membership FKs, fixed first_activation purpose, secret/authority/subject SHA-256 hashes, expires_at (30-minute issuance baseline), consumed_at, invalidated_at and timestamps. Composite membership foreign keys prevent cross-school proof links. No plaintext activation secret or temporary password is stored in this table.

Every new staff provisioning entry point, including the legacy device endpoint, sets activation_required and delegates to the activation proof workflow. Existing accounts are not backfilled or reset. Down removes the proof table before its new user flag. Run only in disposable test databases until production migration is separately approved. MariaDB CI executes fresh/rollback/remigrate and an independent two-process single-use race. School locks serialize activation/reissue with provisioning; identity rows are locked in ID order, then membership/proof rows. Audit events remain append-only under existing triggers. No profile/guardian tables are fabricated.

## AUTH-05 additive recovery proof migration

`2026_10_29_000001_create_account_recoveries.php` adds only `account_recoveries`: ULID PK, school/user FKs, same-school target/issuer composite membership FKs, password_recovery purpose, SHA-256 secret/authority/subject hashes, verification method/reference hash/time, 15-minute expiry, consumed/invalidated timestamps. No contact/profile records or existing-account backfill. Down drops this table. Initial identity and activation migrations unchanged. Recovery locks follow activation order (school, sorted user IDs, memberships, proof), with audit and credential/token/session/proof changes in one transaction. Use disposable MariaDB for fresh/rollback/remigration and independent-process replay race tests; never run CI reset commands against real data. Verification evidence remains in the school's restricted case register; only an opaque reference hash is persisted here.

## AUTH-06 additive MFA migration

`2026_10_29_000002_create_mfa_credentials.php` adds global identity-owned mfa_credentials (user PK/FK, authenticated encrypted seed, ULID enrollment version, pending expiry, confirmed time, last accepted counter) and mfa_recovery_codes (ULID PK, user FK, unique SHA-256 hash, consumption time). No existing identity/proof migration is rewritten. Down removes recovery codes before credentials. School/identity/membership locks precede credential/code locks. Two independent MariaDB races verify TOTP and recovery-code single-winner consumption. No platform credential issuer or production encryption key is created.

## AUTH-07 session revocation tombstones

`2026_10_29_000003_create_session_revocations.php` adds session_revocations: SHA-256 session-ID digest primary key, user FK, creation timestamp. No raw cookie/session payload is stored here. Deletion of a sessions row alone cannot defeat a concurrent session writer; this permanent denial marker is checked on protected browser requests and MFA assurance. Retention/cleanup is a future reviewed operational concern; no tombstone purge API is provided. Down drops only this new table. Existing sessions and Sanctum records remain the inventory source; unknown historical start/absolute-expiry metadata is not invented.

## AUTH-08 additive permission/audit migration

`2026_10_29_000004_add_permission_version_and_audit_details.php` adds unsigned memberships.permission_version default1 and nullable audit_events.details JSON. No identity/proof backfill, new profile/contact records or production migration. Current permission/role/status mutations serialize on the school, lock identities/memberships, compare expected version and append before/after audit details. Existing append-only audit triggers also protect details. Down removes only these two columns. Hosted disposable MariaDB must verify fresh/rollback/remigrate on the final PR HEAD; never run reset commands on real data.
