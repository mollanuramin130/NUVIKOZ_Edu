# Current checkpoint — AUTH-04 account activation

Draft PR #3: https://github.com/mollanuramin130/NUVIKOZ_Edu/pull/3 . Tested implementation checkpoint: `5d03a41192b94b177df8a1082ebd02c5ab22cdbd`. Subsequent evidence updates are documentation only; resolve their actual branch tip and checks. Branch: `feature/m1-auth04-account-activation`, created from verified main `248917192c9d118c9e2ff3630a9e530a3e1c630a` (PR #2 merged). No concurrent main changes observed before coding. Earlier open-PR #2 statements below are obsolete. Resolve actual remote branch tip and Draft PR checks before continuing.

Implemented School Admin browser staff provisioning/reissue and anonymous proof consumption, 30-minute hashed single-use proofs, locked authority/subject rechecks, permanent invalidation on model security changes, password hashing, audit, pending-account mobile bypass denial and minimal Next.js `/activate` page. One additive migration: `2026_10_28_000003_create_account_activations.php`. No student/parent provisioning or AUTH-05/06 functionality. Existing device API remains compatible for legacy accounts.

Local PHP suite: 58 tests discovered, 57 passed / 222 assertions; one independent MariaDB concurrency test skipped locally because the local test driver is SQLite. Pint and Next.js lint/type/build passed. MariaDB CI on the tested implementation checkpoint passed 58 tests / 229 assertions, with no skips, including two independent processes consuming the same proof (exactly one winner), plus 12 HTTPS browser tests including provisioning → invalid proof → successful activation → replay denial → browser login. Migration fresh/rollback/remigrate, Composer audit/platform checks, Pint, web audit/lint/type/build, repository integrity and metadata-blocked Flutter all passed: https://github.com/mollanuramin130/NUVIKOZ_Edu/actions/runs/40748197059 . The local synthetic handover adapter only returns a fresh proof to the authorized issuing School Admin in local/testing; production issuance fails closed with full rollback. No external message, plaintext proof persistence or production configuration.

Next safe step: owner review of this AUTH-04-only Draft PR at its actual tip. No implementation blocker remains for the authorized local/testing workflow. Production delivery is deliberately disabled, not approved by this milestone. No merge/deploy authorized. Preserve metadata-blocked Flutter policy and Nursery isolation. Read the activation API subset and migration addendum. Do not add password recovery/MFA in this increment.

Earlier handoff follows as historical evidence.

# Current checkpoint — AUTH-01–03 browser implementation

Updated 2026-10-28. Branch: `feature/m1-browser-auth-account-lifecycle`. Draft PR #2: https://github.com/mollanuramin130/NUVIKOZ_Edu/pull/2 . Tested checkpoint: `3889b292c2af555258f68bdaf214af75203dd5ea`. The subsequent handoff/ADR commits are documentation only; resolve the actual branch tip and its checks before continuing. Repository is PUBLIC. Starting remote HEAD was 370c1836ddf3a832408440bb313e09bf17089e5c; no concurrent changes observed before coding.

Implemented dedicated browser session/CSRF/origin boundary, Sanctum web-guard authentication, database/encrypted host-only Secure HttpOnly cookies, session rotation, current-user school checks/expiry, login/logout, and Next.js credentialed login/protected shells/expiry/denial screens. Modules: API BrowserAuthController, BrowserBoundary/BrowserCsrf/BrowserSession, browser routes/config; web browser-auth client, protected dashboard and authentication pages; HTTPS browser test harness and MariaDB CI. Existing sessions migration reused; no new migration. See ADR-021 and authentication contract addendum.

Executed evidence on tested checkpoint: M1 Foundation https://github.com/mollanuramin130/NUVIKOZ_Edu/actions/runs/40738175209 — API, web and metadata-blocked Flutter all passed. API PHP 8.4: 42 tests/126 assertions against MariaDB 10.11, Pint, Composer audit/platform checks, fresh/rollback/remigrate passed. Five real HTTPS Playwright tests passed against Next.js/Laravel/MariaDB: CSRF/origin/CORS rejection, actual UI login/logout and host-only cookie flags, invalid/suspended/inactive login, session fixation and logout replay, cross-school context, wrong-role navigation and expired database sessions. Web npm audit, lint, TypeScript and production build passed. Repository integrity https://github.com/mollanuramin130/NUVIKOZ_Edu/actions/runs/40738175250 passed. Local PHP suite 42/126 and web lint/type/build also passed. No local Flutter execution.

Integration initially exposed shared CSRF/login throttle counters and unauthenticated non-JSON requests attempting an absent login redirect. Fixed separate throttle namespaces and JSON guest behavior; regression test and full real integration now pass. Test TLS certificates are ephemeral and verification relaxation is confined to the isolated test client. Production cookies remain Secure and exact origins fail closed. Limited integrity secret scan found no obvious committed secrets; no real user data used.

NEXT SAFE TASK: independently review Draft PR #2 at its actual tip. Do not merge without owner authorization. Then complete AUTH-04 account activation as one bounded workflow with atomic single-use/expiry/tenant/authority tests. AUTH-05 recovery, AUTH-06 privileged MFA, AUTH-07 full device/session management, AUTH-08 safety regression and AUTH-09 mobile authentication remain pending. Platform and forced-password-change browser login remain denied. School Admin provisioning stays separate from Principal admission profiles. Same-site HTTPS Edu web/API hosting is required; do not broaden cookie domains for cross-site hosting. No production/Hostinger/Nursery changes. Flutter metadata-blocked policy unchanged.

Earlier handoff below is historical evidence and does not override this checkpoint.

# Canonical status — merged foundation and authentication increment

PR #1 was owner-authorized and squash-merged from exact approved HEAD ff0da5a6536e207b1ecd17796f85c800f153b707. Actual merge/main SHA: c4ab7ae8cc4569829eb1556a812859f2b02e6aaf. Seven checks passed; no conflicts or blocking reviews were displayed. Main repository-integrity check passed after merge; full post-merge M1 CI on main passed API, web and metadata-blocked Flutter at https://github.com/mollanuramin130/NUVIKOZ_Edu/actions/runs/40698176072 .

The next remote branch, feature/m1-browser-auth-account-lifecycle, was created from merged main. Local API verification passed 38 tests / 109 assertions and Pint. Initial work adds a fail-closed exact-origin validator and negative tests for browser CORS/CSRF integration. It is not yet wired into live browser authentication; no new login/session endpoint is claimed. CI push coverage now includes main and this feature branch. Read M1_AUTH_INCREMENT_2.md and its contract before implementing the session handshake, activation, recovery and privileged MFA.

GitHub reports that branch protection will not be enforced on the then-private repository until the account/repository meets its plan requirements. No plan purchase or account migration was performed. Continue manual exact-HEAD, green-check and explicit merge-approval gates. Required check proposal: api, web, flutter and repository-integrity.

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
