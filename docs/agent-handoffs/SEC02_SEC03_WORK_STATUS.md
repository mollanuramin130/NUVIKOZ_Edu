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
