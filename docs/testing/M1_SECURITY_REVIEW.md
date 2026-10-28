# M1 foundation security review — execution evidence

Date: 2026-10-28. PR #1: https://github.com/mollanuramin130/NUVIKOZ_Edu/pull/1 . Existing branch: `feature/m1-tenant-auth-foundation`. Actual review base was verified remotely as `618e6cf24adf767d5c3a70660f508341f50dbe33`; main remained at `71047d68d04f85c4b41ec28a85bb9a39367e7578`. No concurrent changes were observed before editing. The GitHub plugin returned 404 for the private repository; previously authorized browser fallback was used.

## Findings disposition

See ADR-020 for each finding, threat boundary and correction. School token scope, suspended/platform school-login issuance, privileged provisioning service validation, bulk audit mutation bypasses, missing HTTP error headers and mobile origin validation were corrected. No unresolved critical/high finding was identified in the implemented foundation during this bounded source/test review. This is not an independent penetration test or approval to deploy incomplete M1 authentication.

## Actual execution

| Check | Evidence/result |
|---|---|
| Local PHP 8.4.1 / Laravel 13.39.0 | Pint passed; PHPUnit with `--display-warnings --fail-on-warning` passed **37 tests / 99 assertions** |
| Composer audit | No advisories or abandoned dependencies reported for the lock at review time |
| npm audit, full dependency set | Zero known vulnerabilities reported; production-only scan also zero |
| MariaDB CI | Corrected suite passed **37 tests / 99 assertions**, including query-builder UPDATE/DELETE audit denial and composite tenant foreign keys |
| Schema reversibility CI | Fresh migration, rollback and remigration passed, including additive audit trigger migration |
| Web CI | npm install/audit, ESLint, TypeScript route generation/check and production build succeeded |
| Flutter safe CI | Official checksum-verified Flutter 3.47.8 / Dart 3.13.7; dependency resolution succeeded, analyzer reported no issues, **3 tests passed** |
| Flutter isolation evidence | CapEff `0000000000000000`, NoNewPrivs `1`; local firewall rules checked before execution; no metadata connectivity probe |
| Repository integrity | Import/provenance hashes, target API references, fixed storage policy and 7 sample hashes validated; tracked-source scan found no obvious secret patterns |

Reviewed corrected code commit: `5ad94647949089dfe1360470ba1c770ff87d56c5`. CI summary: https://github.com/mollanuramin130/NUVIKOZ_Edu/actions/runs/40693663225 . API log: https://github.com/mollanuramin130/NUVIKOZ_Edu/actions/runs/40693663225/job/122834687083?pr=1 . Flutter log: https://github.com/mollanuramin130/NUVIKOZ_Edu/actions/runs/40693663225/job/122834687155?pr=1 . Subsequent CI artifact run `2885fb9f02d7eb86f95ee075c43f68190daeaafd` also passed API/web/Flutter; the resolved lock was retrieved and digest-verified. Final lock-enforced current-head checks must be observed after documentation/import commits; do not infer them from these earlier runs.

## Not executed / not claimed

Browser cookie/CSRF integration, activation/recovery/MFA flows and delegated permission tests are not implemented; their tests are NOT RUN. Multi-process race stress testing, emulator/device integration, Android/iOS release builds, Hostinger FPM/Node compatibility and production migration/deployment were NOT RUN. Local MariaDB binding remains unavailable; MariaDB evidence comes from isolated CI. No metadata endpoint test, real notification, real student record, production secret or Nursery change was used.

## Review boundary

Foundation scope is suitable for human review after current-head CI is green. Review-ready is distinct from M1 complete and deployment-ready. Last-SA mutations, verified profile-linked student/parent provisioning, lifecycle and privileged MFA are acceptance gates for the next increment. A platform flag/capability is not a public admin onboarding flow. Main branch protection is still unconfigured; proposed mandatory checks are api, web, flutter and repository-integrity. Require owner approval before merging PR #1.
