# M1 increment 1 — executed verification

Branch: `feature/m1-tenant-auth-foundation`. Draft PR: https://github.com/mollanuramin130/NUVIKOZ_Edu/pull/1 . Main baseline: `71047d68d04f85c4b41ec28a85bb9a39367e7578`.

| Check | Actual result |
|---|---|
| Laravel 13.39.0 / PHP 8.4.1 local | 29 feature tests, 82 assertions passed on SQLite after final credential-lock/audit refinement |
| Composer 2.10.3 | Strict manifest validation and locked platform requirements passed |
| Laravel Pint | Final `--test` passed |
| Versioned routes | 14 routes observed through `artisan route:list` |
| Next.js 16.4.3 / React 19.2.8 / Node 24.22.1 | ESLint, `next typegen` plus TypeScript, and production build passed |
| Repository integrity | Approved import hashes/provenance, 7 synthetic sample hashes, target contract references and CSV structure passed; no obvious secret patterns in tracked source |
| MariaDB locally | Datadir initialized, server socket binding denied; no local integration pass claimed |
| First GitHub Actions run | API and web succeeded at code commit `1394211bf889f96affd496f3b705b29329c19108`; run https://github.com/mollanuramin130/NUVIKOZ_Edu/actions/runs/40689644038 |
| MariaDB CI migration cycle | Fresh migration, rollback and migration succeeded against isolated MariaDB 10.11 service |
| First CI test caveat | 82 assertions executed with 29 missing-environment-file warnings; follow-up workflow prepares `.env` from the example and fails on warnings. Latest checks must be observed. |
| Flutter | Not run; automatic approval review blocked unexpected cloud metadata access to `169.254.169.254`. No analyze/test/build/lock or Android platform generation claimed. |

The tests cover health/readiness, JSON contracts, validation, token authentication/revocation, expiry, platform separation, School Admin role boundaries, foreign-school denial, immutable IDs, audit model operations, composite tenant foreign keys, idempotency and closed Demo/ingestion/homework upload paths. All data is synthetic. The inherited trivial assert-true example was removed.

This is the first M1 increment, not complete authentication or all target API modules. Browser session/CSRF, activation/reset/MFA, delegated permission services and profile-linked child/guardian provisioning remain pending. Live upload quotas are preserved as contracts; uploads remain closed. Audit history is model-protected, not yet database-admin-proof. Secret-pattern scanning is not an exhaustive security audit.

No main merge, production deployment, Nursery change, DNS change, live database migration, purchase, real message or mobile publication was performed. Approved design documents and target contracts remain authoritative.
