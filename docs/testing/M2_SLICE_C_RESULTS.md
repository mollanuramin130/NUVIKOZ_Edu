# M2 Slice C — real web integration evidence

Implementation `fdd7bee706ab724ace4bba02e2b257e945db2889`, branch feature/m2-academic-setup, Draft PR9. These are exact-source results; future commits need independent final-head checks. No backend domain source/migration or mobile/Android/SDK/dependency-lock change in this slice.

## Executed hosted checks

- M1: https://github.com/mollanuramin130/NUVIKOZ_Edu/actions/runs/40939278449 — API, Web, Flutter PASS.
- Integrity: https://github.com/mollanuramin130/NUVIKOZ_Edu/actions/runs/40939278352 — PASS.
- API job123678025943: **149 MariaDB tests /994 assertions**, no skips, including all24 independent-process races. Disposable MariaDB10.11; migration fresh/rollback/remigrate, Pint, platform requirements and blocking Composer audit passed; no advisories.
- Actual HTTPS Chromium: **28 passed (1.1m)**,20 existing retained +8 new UI cases. New full journey uses actual Next.js login, School Admin MFA enrollment/confirmation, dashboard link and academic screens to create session/class/offering/section/subjects and both compulsory/optional assignments, then verifies backend-connected listings. No mocked business responses replace this path.
- New UI cases additionally verify guests/wrong role/MFA pending; lost response AFTER actual server commit with one stable-key manual retry and double-submit prevention; actual bad-CSRF rejection/handshake with no POST replay; expired entitlement writes denied with historical reads; real cursor pages/page-local search/duplicate conflict; delayed actual private response discarded after another tab changes school/role; revoked membership removes workspace.
- Web job123678026337: npm audit (zero vulnerabilities), ESLint, TypeScript, **7 isolated client unit tests**, production build PASS. Unit tests transpile the actual TS client in an isolated VM with synthetic transport; these are not HTTPS/runtime claims. They cover allowed filters/headers/boolean,419/409/422 no replay, path/query/key rejection, HTTPS fail-closed and abort signal.
- Flutter job123678026245: unchanged metadata-blocked, credential-free analyze and16 tests PASS; CapEff0/NoNewPrivs1. No native/device acceptance implied.

## Actual screenshot and accessibility evidence

Artifact: https://github.com/mollanuramin130/NUVIKOZ_Edu/actions/runs/40939278449/artifacts/12925947849

- Artifact ZIP203015 bytes; SHA256a06dca29e13f724b8011ace53b00c0cae3a2ce4007b8420987ab265a9681124a. Seven-day retention; provider expiry2026-11-05T19:24:09Z. Regenerate via secured CI after expiry.
- sections-desktop.png SHA2566c300eb863c0d76473865da1cae34daa8b79d9b569b95b72e33615181c8c48ab.
- sections-mobile.png SHA256c477fa06776c4b6a6eb4fda694e928a19b4d52ecf7f7798b79a7ada92773ec6d.
- SOURCE_COMMIT is CI's pull-request checkout48a93b51ffe0f8dbabb0f1d1ab953223b142a4ba; artifact metadata binds feature headfdd7bee706ab724ace4bba02e2b257e945db2889. Synthetic academic records only, no login/MFA pages, cookies/HAR/trace or credentials in artifact.
- Both actual PNGs decoded from CI, SHA256 matched to generated artifact manifest and visually inspected: no clipping/overlap; selected context, real section, unavailable teacher assignment and accessible form visible. These are executable Next.js screenshots, not concept renders.
- Desktop1440x1040, tablet900x1000 and mobile390x844 were exercised; tablet/mobile document-width checks passed. Native labels,44px button, outline and Tab-to-submit passed. Core token contrast pairs measured5.90–14.31 text,4.50 focus and3.76 control border (details in design doc). No full WCAG/screen-reader certification claimed.

## Findings and limitations

Initial runs40937485869 and40938307917 correctly failed the new UI suite (25/28 then27/28 passed). Two unscoped form-alert locators also matched Next's hidden route announcer. The mobile test selected an identically labeled control on the old Subjects page before App Router committed Sections. Diagnostic screenshot confirmed the new page had no selected offering and its session context was intact; the captured200 RSC response was not an API success. Destination-heading waits and exact API-origin response matching fixed the harness, preserving and strengthening section/list/keyboard assertions. No auth/MFA/backend weakening was introduced. Mobile secondary text was made more legible after actual screenshot inspection.

Local ESLint/typecheck/build and7 client tests passed. Owner final visual/feature review remains pending; no M2 full acceptance or merge. Context/focus revalidation clears unsaved private forms rather than retaining them. Teacher assignment, active session transitions/approvals, onboarding, complete dark mode, mobile academic scope and formal accessibility review remain unavailable/deferred. SEC-01 MethodChannel physical runtime acceptance remains separately pending; no new APK/download requested. Production issuer, lost-factor reset and real delivery remain disabled. Main and PR6/7/8 untouched.
