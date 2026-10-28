# Canonical status — M1 increment 1

M1 is in progress. The first development increment is in draft PR #1: https://github.com/mollanuramin130/NUVIKOZ_Edu/pull/1 . Work is isolated on `feature/m1-tenant-auth-foundation`. The verified main baseline is `71047d68d04f85c4b41ec28a85bb9a39367e7578`; no merge is authorized.

Laravel 13.39.0 now boots and implements versioned health/readiness, JSON envelopes, request IDs, Sanctum token authentication, explicit school context, platform separation, School Admin teacher/principal provisioning, idempotency and closed upload paths. The schema covers schools, users, memberships, permission assignments, audit events, idempotency, tokens and sessions. Audit immutability currently applies through model operations; database administrator resistance is not claimed.

Local verification: 29 feature tests / 82 assertions passed on SQLite; Pint, Composer strict validation and platform checks passed on PHP 8.4.1. Next.js 16.4.3 lint, type generation/checking and production build passed. The first CI run on code commit `1394211bf889f96affd496f3b705b29329c19108` succeeded for API and web, including MariaDB migration/rollback/remigration. Its API run emitted missing-environment-file warnings. A follow-up workflow prepares a disposable environment and fails on warnings; check the latest PR checks before continuing.

Flutter source and a widget test exist but have not been executed. Automatic approval review blocked Flutter verification after unexpected access to cloud metadata address `169.254.169.254`; no indirect retry is authorized. Local MariaDB could not bind a UNIX socket. Hostinger runtime compatibility remains unverified. No production, Nursery, DNS, live database, paid service, notification or publication changes were made.

Next increment: resolve any latest CI failures, then implement browser cookie/CSRF authentication, activation/reset/MFA, profile-linked student/parent provisioning and delegated permissions with negative security tests. Read ADR-019, M1 migration guidance, implemented API contract and M1 results first. Preserve approved target contracts and storage/business rules; live upload quotas are documented, not implemented by this increment.

The GitHub connector returned 404 for this private repository; the authorized browser fallback was used. The local source mirror was initialized from verified source for diffs; its local Git history is not the remote history. Use actual GitHub PR/head evidence for remote commit IDs.
