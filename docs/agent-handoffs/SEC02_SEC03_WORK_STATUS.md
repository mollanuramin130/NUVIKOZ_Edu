# PR6 owner-review lifetime correction — 2026-10-29

Continues published d514e32b3e4c7d791d8395e7d27c296a348bec92 and saved local51fce363d49f72a93d5ee5fa88fc1ce7ab56374c on same branch/Draft PR6. Reproduced two deterministic real cookie/database/guard HTTP failures: identity200 but status403 at five minutes, and a replacement started at299 seconds rejected confirmation at301 while assurance remained valid. Cause: status/replacement reused pendingIdentity's first-factor challenge deadline instead of the existing15-minute verified-assurance policy.

Minimal correction: PlatformMfa separates common current-context eligibility from strict pendingIdentity challenge validation and authenticatedIdentity (valid current PlatformAssurance OR still-open pending window). Only platform status and platform replacement use authenticatedIdentity. Enrollment/first-factor TOTP/recovery retain pendingIdentity and strict five-minute expiry. Replacement still independently requires current password/old factor at start and current password/new TOTP plus bound pending intent at confirmation. No policy limits, guards, cookie/CSRF/Origin/revocation checks or production gate changed. No client boolean/timestamp grants authority.

Six new deterministic HTTP cases cover verified status/start/confirm beyond five minutes, confirmation spanning the boundary, expired pending enrollment/TOTP/recovery/replacement denial, exact15-minute assurance expiry with unchanged in-flight credential, and strict30-minute idle/12-hour absolute boundaries. Idle/absolute isolation uses persisted server-side timestamp fixtures; never client-supplied timestamps. Existing invalid binding/version/credential/identity/revocation/school cases now also deny status/replacement after six minutes. Contracts clarify the distinction.

Local full PHP:145 discovered,124 passed/919 assertions,21 MariaDB-only concurrency cases skipped. Pint and Repository Integrity passed. Hosted MariaDB/replacement races/full25 HTTPS/Next.js/four-check evidence must come from the NEW published HEAD, not prior green results. Next safe task: publish this narrow correction, verify source equality and actual new-head CI; keep PR6 Draft for owner review. No migrations/Next.js source/SEC-01/mobile/native/Flutter-isolation changes. No production issuer/lost-factor reset, merge or deploy.

# Historical verified checkpoint

# Machine-readable implemented contract follow-up

The documentation-only90935d8292dd7f0579f0bb76c4ed9c08d7ac5083 checkpoint passed all four jobs (M1 run40857530594, Integrity40857530616). Added additive m1-platform-replacement.openapi.json for the12 implemented SEC-02/03 operations and clarified the previous school subset description/README; canonical v1.1 design untouched. Contract contains no seeds/password/token examples or production issuer enablement. Local JSON parsing,21 reference resolutions and12 operation matches against actual Laravel route:list passed; full OpenAPI-validator execution NOT RUN (not installed). obtain NEW exact HEAD CI before claiming final readiness. Runtime code/tests unchanged from implementation8122b3e. Next: verify contract follow-up and owner review, no additional modules/merge/deploy.

# Verified supported implementation evidence

# SEC-02 / SEC-03 supported implementation and SEC-04 evidence — 2026-10-29

Branch `feature/m1-platform-auth-recovery`; existing PR #6 remains DRAFT, no merge or deployment. Verified published implementation `8122b3e994eba27bdb61d45da508079d53c69dba` exactly matches local implementation `fc8ad349bfc76915a79b6d7f47696436205f1eef`. Saved checkpoints and remote ancestry are preserved; authenticated browser commits were used because connector writes/push credentials were unavailable. This documentation follow-up requires its own four-check verification; final actual SHA/run must be retrieved from PR6, not assumed from this earlier implementation SHA.

SEC-02 supported synthetic browser journey IMPLEMENTED/TESTED: Next.js platform login/pending MFA/enrollment/confirmation/TOTP/recovery/identity/logout and expired/unavailable states; separate HTTPS/Origin/CSRF and context boundary. Secure-context-safe sender-tagged broadcasts retain cross-tab revalidation without clearing the originating page's newly issued recovery codes. Six SEC-02 HTTPS journeys passed. Production platform issuer/operator bootstrap remains DISABLED, so this is not production authentication completion.

SEC-03 supported replacement IMPLEMENTED/TESTED: current eligible identity/context + password + old TOTP/unused recovery code; encrypted five-minute session/credential/version/membership-bound pending setup, old factor preserved until new TOTP confirmation; locked atomic activation, old factor/codes invalidated, eight new hash-only recovery codes, append-only appropriate school/global audit, previous assurance and all browser/device sessions revoked, fresh authentication required. Functional school/platform replacement UI. Independent lost-authenticator reset remains **BLOCKED/fail-closed**, with no password-only or unverified-contact override. AUTH-05 MFA preservation remains covered.

SEC-04 supported backend/web verification PASSED on implementation8122b3e:
- M1 Foundation run40856651888: API, Web, Flutter PASSED. Repository Integrity run40856652086 PASSED.
- Real MariaDB: **139 tests / 971 assertions**, no skips; **21 independent-process concurrency cases** (13 prior plus8 replacement cases: school/platform concurrent start, concurrent confirmation, password-change race and identity-deactivation race). No weakened assertions or sequential substitution.
- HTTPS browser: **25 passed** =17 pre-existing school journeys +6 SEC-02 platform journeys +1 school replacement +1 platform replacement. Seven new platform journeys in total. Actual Next.js/Laravel/HTTPS/cookie/CSRF boundaries, including ordinary production-entry-point denial.
- Migration fresh/rollback/remigrate, Pint, Composer platform requirements/validation and blocking security audit passed; no Composer advisories. Web lint/typecheck/production build and blocking npm audit passed.
- Unchanged metadata-blocked Flutter baseline: analysis clean, **16 tests passed**. This does NOT verify Gemini's native Keystore/device implementation.
- Local SQLite final implementation: **118 passed / 793 assertions**,21 MariaDB-only cases skipped,139 discovered. Seven new replacement service/rollback/security tests:87 assertions. Final local Web checks and Repository Integrity passed.

New migration000006 creates only transient mfa_replacements; no production database was touched. API contract and migration notes describe implemented fields/routes. No mobile/Android/Kotlin/MethodChannel/native test/Flutter isolation script or Gemini branch was modified. No real notifications, paid infrastructure, school impersonation, unrestricted platform access, DNS/Hostinger/Nursery changes, force-push or merge.

NEXT SAFE TASK: verify this documentation-only follow-up's actual published HEAD and four CI checks, retain PR6 Draft for owner review. Address review findings narrowly. Production issuer/operator identity bootstrap and independent lost-factor recovery remain separate approval/prerequisite gates. Gemini SEC-01 integration/device verification is separate and not authorized by these backend/web test results.

# Historical local SEC-03 checkpoint (superseded by actual hosted evidence above)

# SEC-03 implementation checkpoint — local, awaiting MariaDB/HTTPS validation

SEC-02 browser gate PASSED on published37ad7d320258a4c8e41b73a20b22a356042a0241 (same tree as localb712ea74d7d496ba3d34e1caa4e17ace7644a7f2). M1 run40854659014: API/Web/Flutter success; Integrity40854659010 success. Actual124 MariaDB tests/786 assertions, all13 independent races; all23 HTTPS journeys (SIX new platform +17 school), Composer audit clean, migration cycle/Pint/Web lint/typecheck/build passed. Unchanged metadata-blocked Flutter baseline passed; no Gemini native verification claim. Production issuer still disabled, so SEC-02 remains production-PARTIAL despite supported synthetic browser completion.

SEC-03 new additive000006 mfa_replacements schema, locked context-aware MfaReplacement service, strict browser start/confirm routes, shared replacement UI in school security and platform pages. Current password + actual old TOTP/unused recovery code required; encrypted five-minute pending seed bound to session/context/credential/MFA version, old factor preserved until new TOTP confirmation. Atomic swap/code rotation/revocation/audit; fresh login required. No lost-factor reset: BLOCKED pending independent approved verification. No mobile/native/Flutter-isolation/Gemini changes.

Local seven replacement tests/87 assertions pass: both contexts, hashing/encryption, wrong/replayed/expired proof, old-factor preservation, role/school/identity/context/session checks, cross-school membership binding, revocation and audit-failure rollback. Eight new independent MariaDB races (both contexts × concurrent starts/confirmations/password changes/deactivation) are NOT RUN locally. Two new real HTTPS replacement journeys are written (25 total) but NOT RUN until hosted CI. Existing password-recovery-preserves-MFA regression remains enabled. Full final local PHP/Pint/Web/integrity/audit checks and hosted MariaDB/HTTPS evidence must be recorded, never inferred from the SEC-02 SHA.

NEXT SAFE TASK: validate final local tree, publish coherent SEC-03 to same branch/Draft PR6 after checking actual remote HEAD, verify source equality and all four new-head checks. Fix only actual failures. SEC-04 pending full new-head regression; never merge/deploy or enable production issuer. Preserve all local/remote history.

# Historical SEC-02 transport correction checkpoint

# SEC-02 secure-context broadcast compatibility correction

Second UI HEAD bc556c8f441910b8d84f7c563fdab2c6020b4e56, run40843406462: all SIX platform HTTPS journeys passed;22 total browser tests passed. One existing school test failed because unconditional top-level crypto.randomUUID() was unavailable on insecure HTTP, preventing the login page from rendering its HTTPS-required error. Sender ID creation is now gated by isSecureContext; insecure pages still cannot submit credentials because the existing browserRequest HTTPS guard remains unchanged. No insecure random credential/storage fallback introduced. All four jobs must be verified on the new publication before SEC-03. Previous full PHP/MariaDB124/786 passed; no transfer of CI conclusions to new SHA.

NEXT SAFE TASK: publish the one-line client compatibility fix plus this checkpoint, rerun all23 real browser tests and four checks. SEC-03 remains NOT STARTED until gate passes; SEC-01 untouched. Preserve same branch/Draft PR6; no merge/deploy.

# Historical UI race correction checkpoint

# SEC-02 HTTPS acceptance correction — awaiting new-head CI

Initial UI publication a388104c84e6ee756e980d0fe27f61afcb3dd52d matched local a5bac78. M1 run40842498667: Web/Flutter/Integrity passed; API PHP/MariaDB passed but browser suite failed 3 platform cases (20 passed, including all 17 school journeys). Actual UI defect: BroadcastChannel notifications also reached a different channel instance in the originating document, racing refresh/secret cleanup with MFA confirmation/logout. The browser client now tags notifications with an ephemeral per-document random sender and ignores its own messages; cross-tab listeners still revalidate. No session/MFA policy change. Playwright waits explicitly for recovery-code rendering and completed logout. Expired assurance correctly returns to pending MFA with no protected identity; test now asserts that actual safe UX and retains API denial.

Local lint/typecheck/build passed for correction; full PHP unchanged (111/698 locally, 13 MariaDB skips). New hosted run is required before SEC-02 browser acceptance or SEC-03. Keep Draft PR6, same branch. Production issuer remains disabled, SEC-01 untouched. NEXT SAFE TASK: publish this narrow UI-race correction, verify all 23 HTTPS journeys and four checks on exact new HEAD, then continue SEC-03. Do not claim browser gate passed from the failed run.

# Historical initial UI checkpoint

# SEC-02 platform UI and HTTPS journeys — local implementation checkpoint

Continues local a9807e241ca9fb24a5f4d554ca4f9f922ae43dfc and verified published dc8acad1042597daf3148882434b0d32c4e70fb7, same branch and Draft PR6. Added `/platform` UI using existing credentialed/CSRF client, with separate pending/enrollment/TOTP/recovery/identity/logout and unavailable/expired states. No client privilege toggle. API contract now describes actual synthetic HTTP subset.

Added six platform Playwright tests (23 total including 17 existing school journeys): actual UI enrollment, existing TOTP challenge, single-use recovery, session rotation/stale-cookie/logout, expiry, CSRF/Origin/bearer/cross-context and ordinary production-entry-point issuer denial. These are NOT RUN locally yet; hosted CI must execute them before any SEC-02 browser completion claim or SEC-03 work. Separate loopback CLI-server harness is testing/edu_test-only; normal app config stays false and production services remain gated. Fixture mutations require CLI/testing/edu_test. Existing Flutter isolation script/mobile untouched.

Local PHP:111 passed/698 assertions,13 MariaDB-only skips (124 discovered). Pint passed for new PHP scripts. Web lint and typecheck passed after fixing an initial effect lint error; production build passed before that effect-only correction and must be rerun on final tree. Repository Integrity passed. Real MariaDB/HTTPS and final-head hosted checks pending; do not reuse previous SHA's evidence. SEC-02 PARTIAL, SEC-03 NOT STARTED, SEC-04 PARTIAL. No merge/deploy/production enablement.

NEXT SAFE TASK: finish local final-tree checks, publish coherent UI/harness/tests to existing branch/PR6, run all four hosted checks; fix genuine failures before advancing to secure MFA replacement. Lost-factor reset stays BLOCKED pending approved independent verification.

# Historical published session-fix checkpoint

# Published HTTP checkpoint and hosted evidence — 2026-10-29

Existing branch `feature/m1-platform-auth-recovery`, Draft PR #6. Published source HEAD `da9171b05194777ef791a8bcf16059d36e70d677` exactly matches local code checkpoint `33f9bc58138c8ce9ce6e318140fd0f59fb2c7215` (all files, not only selected paths). Original saved commit `e17908953c711415d3e0d7183992e1af80bc5e48` and every earlier commit remain preserved. Browser-created commits were necessary because connector mutations/push credentials are unavailable; no force-push or history replacement.

Hosted verification on that exact published source HEAD: M1 Foundation run `40839517931` — API, Web and Flutter PASSED; Repository Integrity run `40839517894` PASSED. API: **124 MariaDB tests / 786 assertions**, no skips, including all **13 independent-process concurrency tests**; migration fresh/rollback/remigrate, Pint and blocking Composer audit passed (no advisories). Existing HTTPS Next.js/Laravel school browser suite: **17 passed**. Web: lint, typecheck, build and audit passed. Unchanged metadata-blocked Flutter baseline: analysis clean, **16 tests passed**; no claim of native Gemini implementation verification. Local SQLite: **111 passed / 698 assertions, 13 MariaDB-only skips**, 124 discovered. Seven new real middleware/database/cookie platform HTTP tests: **112 assertions** locally. This documentation-only follow-up must still receive its own final-head CI; do not transfer prior run status implicitly.

The platform protected identity regression is resolved with NO production assurance-policy weakening: JSON HTTP tests now explicitly send credentialed cookies and recreate per-request guards/session stores, eliminating retained in-process state. Root-cause details and coverage below. SEC-02 remains PARTIAL (Next.js platform UI and genuine platform Playwright journeys missing); SEC-03 NOT STARTED; SEC-04 PARTIAL. Production issuer/bootstrap and unapproved lost-factor reset remain unavailable/fail-closed. PR remains Draft. No merge/deploy or Gemini SEC-01/mobile/native/isolation changes.

NEXT SAFE TASK: verify this documentation follow-up's exact remote HEAD and four checks, then continue SEC-02 minimal platform UI and genuine HTTPS browser journeys. Do not reopen the fixed session mismatch or start SEC-03 ahead of verified SEC-02 boundary integration. Production enablement still requires separate owner authorization and fulfilled operator prerequisites.

# Historical local HTTP checkpoint (superseded by published evidence above)

# SEC-02 HTTP session regression resolved — 2026-10-29

Branch `feature/m1-platform-auth-recovery`, existing Draft PR #6. Continues saved local `e17908953c711415d3e0d7183992e1af80bc5e48`; last published service checkpoint `251e910a7ad9a58ffcc76e4470a40749ca9eb4ea`. This update is the HTTP checkpoint, not completed SEC-02 or SEC-03. Remote SHA and hosted results must be retrieved after publication, not inferred from local commits.

Root cause: `PlatformBrowserTest` used JSON requests without Laravel `withCredentials()`. `prepareCookiesForJsonRequest()` therefore omitted the carried cookie. The reused in-process session store retained attributes while StartSession selected a new identifier; the correctly enforced assurance session digest then differed. No production session binding, rotation, guard, revocation, CSRF, Origin or issuer gate was changed to fix it. The test now sends the encrypted cookie and resets guard/default resolver/session-store instances between requests so each request reloads only persisted database state.

Seven platform HTTP tests pass (112 assertions), including real first-factor/enrollment/confirmation, password-only denial, session rotation and old-row deletion/tombstones, final persisted assurance digest, secure host-only HttpOnly SameSite cookie round-trip, current identity, rotated-CSRF logout, stale/copy/deleted/revoked-session denial, school-context exclusion, actual password and MFA version invalidation, idle/absolute/assurance expiry, audit-insertion rollback, hostile Origin, invalid/missing CSRF, bearer substitution and production denial. Existing school regressions remain enabled. Synthetic HTTP routes remain testing-only AND disabled-by-default config; no production operator issuer/bootstrap is enabled. No secrets/identifiers were printed in diagnostics.

Local complete PHP suite: 124 discovered, 111 passed, 698 assertions, 13 real MariaDB concurrency tests skipped under SQLite. Pint and Repository Integrity results are recorded by executable validation for this checkpoint. Hosted MariaDB/HTTPS/Flutter/four-job CI require NEW published-head evidence; prior run40836128332 does not establish this checkpoint. No platform Playwright journey/UI exists yet. Existing 17 school HTTPS journeys are not platform journey evidence.

SEC-02 PARTIAL: HTTP synthetic flow and session boundary now verified locally; minimal Next.js platform screens and genuine HTTPS platform browser journeys remain pending. SEC-03 NOT STARTED; unapproved lost-factor reset stays fail-closed. SEC-04 PARTIAL until final-head hosted validation. No Gemini SEC-01/mobile/native/isolation files changed. No merge/deploy.

NEXT SAFE TASK: publish this tested HTTP checkpoint to existing branch/PR6, verify source equality and all four new-head CI checks (including 13 MariaDB races). Then continue SEC-02 platform UI and actual HTTPS browser integration; only afterward SEC-03 replacement.

# Historical checkpoint — failed HTTP test (superseded above)

# SEC-02 HTTP integration checkpoint — FAILING TEST, NOT PUBLISHED

Continues local5dad956 and published251e910a7ad9a58ffcc76e4470a40749ca9eb4ea on same branch/PR6. Prior published CI passed117 MariaDB/660 assertions,13 races,17 school HTTPS journeys,16 Flutter tests (run40836128332). No transfer of that evidence to new HTTP work.

Added PlatformBrowser gate (testing+config only), strict login FormRequest and PlatformBrowserController routes under existing /api/v1/browser/platform. Integrates first factor, pending status, enrollment/confirm/challenge/recovery, guarded identity, audited logout/tombstone. School successful login clears stale platform context. Exposed pendingIdentity method reuses actual service validation. No Gemini/mobile/native/isolation changes. No production issuer enabled.

New real Laravel cookie/CSRF integration test passes login→enrollment→confirmation but FAILS subsequent protected identity with403: PlatformAssurance enrollment/version/user/credential checks true, session digest binding false. Diagnostic instrumentation was removed; no secrets logged. Must diagnose session rotation/store/guard/cookie carry ordering without removing session binding or weakening assertions. Origin/CSRF/production negative test passes. This HTTP checkpoint has NOT been published; existing Draft PR6 remains prior passing service tree. Previous full local suite before new HTTP test passed104/586 assertions with13 skips; do not claim full new HTTP suite pass.

NEXT SAFE TASK: reproduce `php vendor/bin/phpunit --filter PlatformBrowserTest`; fix real session-id mismatch, run complete PHP suite and actual HTTPS platform tests, then minimal Next.js UI. Existing BrowserBoundaryTest guard inventory now separately asserts PlatformBrowser for platform namespace (and auth:sanctum on protected routes); not school-only BrowserSession. Review per-request platform expiry/idle before publication. SEC03 NOT STARTED. No merge/deploy.

# Historical checkpoint evidence

# Platform MFA rollback checkpoint — PARTIAL

Added actual database audit-insertion fault injection for enrollment, confirmation and recovery consumption. Tests assert rollback of encrypted pending state/counter/confirmation, recovery consumption and audit; no new session or assurance on failure. Existing assurance remains unchanged when a later failed challenge rolls back. Local suite117 discovered:104 passed/572 assertions,13 MariaDB-only races skipped. Pint passed. Next: publish this coherent service/test checkpoint for hosted MariaDB; then HTTP routes/platform middleware/UI and HTTPS integration. SEC02 is not complete; SEC03 not started. No mobile/Gemini changes.

# Platform concurrency checkpoint — PARTIAL, local only

Added two real independent-process MariaDB tests for platform TOTP/recovery single-winner consumption, scoped audit count and one consumed recovery record. Workers establish actual synthetic first-factor context via PlatformFirstFactor, receive proofs via stdin only, synchronize before verification, and fail on unexpected errors. These two tests are NOT RUN locally (MariaDB unavailable); they join11 existing CI-only races. Do not claim hosted acceptance. Next task: audit-failure rollback tests then publish a coherent service test checkpoint/Draft PR for real MariaDB execution; browser middleware/routes/UI and SEC03 still pending. Previous first-factor/MFA service commits remain intact. No mobile/Gemini changes.

# SEC-02 platform MFA service checkpoint — PARTIAL

Existing branch feature/m1-platform-auth-recovery resumes9f48400; no Gemini/mobile changes. Added PlatformMfa internal synthetic-only enrollment, encrypted10-minute pending seed, no confirmed-factor overwrite, real existing Totp verifier, strict monotonic counter, hash-only random recovery codes, user/credential row locks,10 attempts/5min, five-minute first-factor deadline, current identity/credential/context/session/tombstone checks. Atomic audit with DB mutations; enrollment confirmation revokes old device/browser contexts. Successful confirmation/challenge/recovery rotates session+CSRF then creates separate15-minute platform assurance after commit. Production remains impossible through testing-only/config-default-false gate; no public route or operator bootstrap.

Executed local PHP:114 discovered,103 passed/559 assertions,11 MariaDB-only tests skipped under SQLite. Pint passed. New test exercises real first factor→enrollment→TOTP confirmation→platform assurance, encrypted-at-rest secret, session rotation, TOTP replay denial with unchanged audit, recovery consumption/replay and expired first-factor denial. This is service acceptance only, NOT HTTP/HTTPS/MariaDB concurrency evidence. No source secret is committed (all test seeds generated). No new migration beyond previously committed000005 platform audit.

NEXT SAFE TASK: add independent MariaDB same-code/platform-recovery races, audit-failure rollback/identity-security-change acceptance; implement platform pending-session middleware and strict HTTP routes/controller/Next.js screens with full Origin/CSRF and cross-context tests. Keep legacy device platform health denied. Do not mark SEC02 complete: routes/UI and hosted acceptance missing. SEC03 NOT STARTED; SEC04 PARTIAL. No publication or Draft PR yet. Preserve existing commits; resume same branch. Production issuer/bootstrap and independent lost-factor reset still gated.

# Historical checkpoints

# SEC-02 first-factor/audit checkpoint — PARTIAL

Existing branch feature/m1-platform-auth-recovery preserved from8f1f80c. Added PlatformFirstFactor internal synthetic-only command: strict identifier/password input, no school/bearer substitution, bounded attempts, locked eligible identity/password checks, transactional platform audit, session/context rotation after successful audit, five-minute pending challenge and no privileged assurance. No public route/issuer/UI or real operator bootstrap is exposed. New additive000005 platform_security_audits migration uses a genuine global user FK and append-only UPDATE/DELETE triggers for SQLite/MariaDB, never a fake school. Existing device platform health remains closed.

Pint passed. Full local SQLite regression executed;11 real MariaDB races remain CI-only and were skipped locally. New tests prove first factor rotates session without granting authority, unknown/school identity denial has no audit/session mutation and raw query-builder audit updates fail. No native/Flutter/Gemini file changed.

NEXT SAFE TASK: implement platform pending-session validator and real TOTP enrollment/challenge/recovery with separate context-bound assurance, atomic replay/audit and failure rollback tests; then route/UI integration and independent MariaDB/HTTPS acceptance. First-factor source is not a completed authentication journey. SEC-03 NOT STARTED; SEC-04 PARTIAL. No publication/Draft PR/hosted CI yet. Production issuer, real bootstrap and unverified lost-factor reset remain unavailable. Preserve this commit; do not restart or create another branch.

Previous policy checkpoint follows as history.

# Backend/web security continuation — initial policy checkpoint

Branch feature/m1-platform-auth-recovery, base main c9ae3cd5d5cc2dc7683fe61576fbc851f49b85ff. SEC-01 belongs exclusively to Android Studio Gemini; no mobile source or Flutter isolation script modified.

SEC-02 PARTIAL: internal PlatformAssurance policy recognizes only platform.health, current activated eligible global identity, confirmed MFA enrollment version, credential binding, separate platform context, session digest, 15-minute assurance, 30-minute idle, 12-hour absolute age and revocation tombstone. School context, bearer substitution, unknown capability and expired/future/stale assurance deny. Configuration defaults false; even true is hard-limited to testing. There is NO platform issuer, route, bootstrap command, MFA verification flow, audit table or UI yet. Synthetic assurance fixtures test the policy only, not real TOTP verification. Legacy platform health remains denied. Production enablement and real operator bootstrap unavailable.

SEC-03 NOT STARTED. SEC-04 PARTIAL: local PHP suite and Pint executed for this checkpoint; consult actual output on continuation. Hosted MariaDB/HTTPS/new-head CI not executed; no remote publication or Draft PR yet. No main merge/deployment or Gemini changes.

NEXT SAFE TASK: implement separate platform first-factor/pending-MFA state and append-only platform audit using existing TOTP primitives, synthetic-only entry gate and strict HTTPS/Origin/CSRF context. Add actual positive/negative MFA and MariaDB concurrency tests before wiring protected identity routes and UI. Then implement SEC-03 replacement with old-factor proof and atomic new-factor confirmation. Preserve this local commit and use one Draft PR only after a coherent externally testable phase is published.
