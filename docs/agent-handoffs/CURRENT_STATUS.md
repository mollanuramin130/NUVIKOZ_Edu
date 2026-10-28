# Canonical status — merged foundation and authentication increment

PR #1 was owner-authorized and squash-merged from exact approved HEAD ff0da5a6536e207b1ecd17796f85c800f153b707. Actual merge/main SHA: c4ab7ae8cc4569829eb1556a812859f2b02e6aaf. Seven checks passed; no conflicts or blocking reviews were displayed. Main repository-integrity check passed after merge; full post-merge M1 CI on main passed API, web and metadata-blocked Flutter at https://github.com/mollanuramin130/NUVIKOZ_Edu/actions/runs/40698176072 .

The next remote branch, feature/m1-browser-auth-account-lifecycle, was created from merged main. Local API verification passed 38 tests / 109 assertions and Pint. Initial work adds a fail-closed exact-origin validator and negative tests for browser CORS/CSRF integration. It is not yet wired into live browser authentication; no new login/session endpoint is claimed. CI push coverage now includes main and this feature branch. Read M1_AUTH_INCREMENT_2.md and its contract before implementing the session handshake, activation, recovery and privileged MFA.

GitHub reports that branch protection will not be enforced on this private repository until the account/repository meets its plan requirements. No plan purchase or account migration was performed. Continue manual exact-HEAD, green-check and explicit merge-approval gates. Required check proposal: api, web, flutter and repository-integrity.

No production/Hostinger/DNS/Nursery changes. Activation/recovery/MFA/browser integration remain incomplete and unverified. Flutter must use the existing metadata-blocked, privilege-dropped CI procedure. Earlier review details follow as historical evidence; this opening section supersedes their pending-merge status.

# Canonical status — M1 foundation review

Updated 2026-10-28. M1 remains IN PROGRESS. PR #1 is the first implemented foundation increment: https://github.com/mollanuramin130/NUVIKOZ_Edu/pull/1 . Existing branch: `feature/m1-tenant-auth-foundation`. Review began from verified remote HEAD `618e6cf24adf767d5c3a70660f508341f50dbe33`; main is `71047d68d04f85c4b41ec28a85bb9a39367e7578`. No merge is authorized. Inspect actual PR HEAD/checks before continuing; local Git history is a source mirror, not the remote commit history.

## Implemented and reviewed

Laravel 13.39.0 / PHP ^8.4.1, 14 versioned foundation routes, identity/tenant schema, request IDs/JSON contracts, Sanctum device tokens, School Admin teacher/principal provisioning, idempotency and closed upload paths. The security review added school-bound token capabilities, platform authority separation, suspended-school login denial, service-level validation/rechecks, database audit row triggers, HTTP protocol headers and mobile origin restrictions. Read ADR-020 and `docs/testing/M1_SECURITY_REVIEW.md`.

Local API suite passed 37 tests / 99 assertions and Pint. Corrected MariaDB CI suite plus fresh/rollback/remigration passed at `5ad94647949089dfe1360470ba1c770ff87d56c5`. Web lint/type/build and dependency audits passed. Composer/npm scans reported no known vulnerabilities at review time. No obvious secret patterns were found in tracked source; this is a limited scan, not a full assurance claim.

Flutter metadata attribution is documented: `flutter --version` initializes tool CI detection, whose Azure fallback probes the metadata address. The newly owner-authorized hosted procedure enforces kernel metadata denial before SDK execution, drops all capabilities/privilege escalation and passes no cloud/GitHub credentials. Flutter 3.47.8 / Dart 3.13.7 dependency resolution, analysis and 3 tests passed. The CI-resolved lock was digest-verified and committed; subsequent CI enforces it. Unrestricted local execution remains prohibited. No Android/iOS release or emulator build is claimed.

## Next safe task

Finish current-head CI/human review, then obtain explicit owner merge authorization. PR readiness is shown by GitHub; readiness never implies permission to merge. Do not carry unrelated lifecycle implementation into PR #1. The separate next branch is `feature/m1-browser-auth-account-lifecycle`, following `docs/milestones/M1_AUTH_INCREMENT_2.md` and `docs/api/M1_AUTH_INCREMENT_2_CONTRACT.md`.

Next scope: Sanctum browser sessions, exact CORS/host-only cookie/CSRF integration with Next.js, school-specific browser authority, activation, safe recovery, privileged MFA, revocation, last-SA/delegation guards and negative tests. Student admission/profile creation remains distinct from SA-controlled login provisioning; student/parent provisioning waits for verified profile/link services. Use synthetic transports; no paid SMS or production delivery.

No unresolved critical/high issue was identified in the implemented foundation during this bounded review. Browser/lifecycle/MFA remain unimplemented, so this is not production-ready. Audit triggers block row mutation but not a database administrator; future runtime DB credentials require least privilege. Main branch protection remains unconfigured. Proposed required checks: api, web, flutter, repository-integrity.

## Scope and handoff rules

Approved target architecture/API/database/storage rules remain authoritative; implemented subsets are explicit. Demo 30 days/zero user files; Free 5 MB/50 PDFs/10 daily/500 MB; configurable paid policy; student homework files disabled. Actual uploads remain closed. Hostinger compatibility, production databases/DNS, paid services, real notifications/mobile publication and the Nursery project were not touched and remain unauthorized.

GitHub plugin access still returns 404; authorized browser fallback was used. Check branch, actual remote SHA, clean/concurrent work and migration state before any future change. Do not force push, overwrite concurrent work or merge based on earlier chat evidence.
