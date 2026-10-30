# Change log

## Unreleased — M2 Slice C

- Owner-approved hybrid academic workspace: secure real API sessions/classes/offerings/sections/subjects forms and lists, reusable responsive components and scoped design tokens.
- Extend browser client with typed filters, cursor/key validation, in-memory idempotent intent and cancellation; add client boundary and actual UI HTTPS tests with synthetic screenshot evidence.

## Unreleased — M2 Slice B

- Add same-school future class offerings, sections, subject catalogs and required/optional subject offerings with composite FKs, idempotency, audit, bounded Demo caps and scoped cursor filters.
- Defer actual teacher assignment; extend negative/security, independent MariaDB race and HTTPS browser coverage.

## Unreleased — M2 academic setup checkpoint

- Browser future-session/class catalog, serialized tenant/MFA/entitlement checks, idempotent commands and audit rollback; no academic UI acceptance claimed.
- Additive academic catalog migration, focused security/MariaDB race/HTTPS tests, dependency roadmap, progress register and proposed UI direction.

## 2026-10-28 — Gate3 initialization

- Created owner-authorized private repository,default branch main.
- Imported approved corrected v1.1 requirements,architecture,database/API contracts,storage policy,test design and shared sample assets.
- Added documentation-first monorepo directories,agent guidance,integrity checks and non-deploying CI.
- Prepared M1 task breakdown. Application frameworks and runtime tests have not been implemented.

## M1 increment1 —2026-10-28

ActualLaravel13.39.0API andinitialtenant/identitymigrations;tokenauth,JSONcontracts,requestIDs,scope/roleguards,SAstaffprovisioning,append-onlyaudit,idempotencypointers and29meaningfulfeaturetests. Next16.4.3TypeScriptsixrolescaffoldtypechecked/built. Flutterminimalpackagemanualsource;runtimeverificationblocked. IsolatedMariaDBCIconfigured;resultrecordedseparately. No productionchanges.

## M1 foundation security review — 2026-10-28

Bound school tokens to the selected tenant; separated platform token authority; denied suspended school login. Added independent provisioning service validation and actor rechecks, database audit UPDATE/DELETE triggers, protocol error headers, and stricter mobile origin validation. API regression suite passed 37 tests / 99 assertions locally and in MariaDB CI. Composer/npm advisory scans found no known vulnerabilities at review time.

Investigated Flutter Azure bot detection and verified the scaffold in a checksum-pinned, metadata-blocked, privilege-dropped hosted CI job: Flutter 3.47.8 / Dart 3.13.7, dependency resolution, analysis and 3 tests passed. No mobile release build claimed. Prepared a separate browser authentication/account lifecycle increment with dependencies and acceptance criteria. No merge or production change.

## M1 AUTH-01–03 browser foundation — 2026-10-28

Added school-scoped database browser sessions, explicit CSRF and exact-origin credentialed CORS, host-only Secure HttpOnly cookies, session rotation/expiry and logout. Added Next.js login, centralized credentialed client, protected role navigation and expiry/denial pages. Added real HTTPS browser/MariaDB CI tests. Account activation, recovery, privileged MFA and device management remain pending. Repository is now public; no production change. See canonical handoff for actual test evidence.

## AUTH-04 — secure account activation

Added School Admin browser staff provisioning with single-use 30-minute activation proofs, authorized reissue, atomic first password setup, authority/tenant/state revalidation and audit events. Added additive proof migration and pending identity flag, mobile bypass denial for new proof accounts, local-only ephemeral handover, `/activate` UI, negative and independent MariaDB race tests. No recovery/MFA, production delivery, merge or deployment. See canonical handoff for actual CI evidence.

## AUTH-05 — assisted password recovery

Added tenant-bound, School Admin-authorized in-person recovery, hashed 15-minute single-use proofs, atomic credential replacement/revocation, scoped rate limits, audit events, and minimal Next.js recovery screens. Public requests are generic and do not fabricate verified contact delivery. Production issuance remains disabled; pending activation cannot use recovery. MFA and production deployment remain pending.

## Advanced M1 batch — AUTH-06 checkpoint

Added School Admin TOTP enrollment/challenges, hashed recovery codes, short-lived server assurance, service-level privileged enforcement, `/profile/security`, RFC-vector/negative/race/browser tests. Legacy device provisioning and platform token health are fail-closed until safe MFA-aware transports exist. AUTH-07–10 remain pending on the coordinated branch; no merge/deploy or production messaging.

## AUTH-07 — server-backed session/device checkpoint

Added owner-only opaque inventories, password/MFA-protected revocation, current/other session/device controls, session resurrection tombstones, security-change hooks and `/profile/sessions`. Ordinary device tokens now use the seven-day baseline; privileged tokens remain twelve hours and cannot bypass MFA. Full AUTH-07 concurrency acceptance and AUTH-08–10 continuation remain pending.

## Advanced authentication continuation (Draft PR #5)

- AUTH-07 active expiry filtering, atomic credential/membership mutation revocation and independent issuance/revocation races; explicit staff/family/absolute boundary tests.
- AUTH-08 school-bound fixed capability registry, versioned grant/revoke and audit, minimal permission UI, live stale-authority checks and serialized last-active-SA membership guards.
- AUTH-09 typed device login context, bounded HTTPS transport and Flutter auth states/UI/tests. OS persistence handler remains absent and fails closed; no native build/live device verification claimed.
- AUTH-10 recovery-with-enrolled-MFA and real HTTPS session/permission regressions. Current evidence/remaining dependencies are in the canonical handoff.
