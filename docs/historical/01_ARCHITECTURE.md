# NUVIKOZ Edu — Architecture Review Package

Version 1.0 • 28 October 2026 • Owner review at Gates 1–2

**Status: proposed architecture, not an implemented or deployed product.** No Edu repository, production resource, domain, payment provider or outbound messaging has been created or changed. The attached master prompt v3.0 is the requirements authority. This package contains architecture documents and design contracts only. Proposed technical defaults require the architecture gate; legal, deployment and spending decisions retain separate gates.

## 01. Executive overview, scope and success criteria

Build a configurable Indian education ERP for an initial controlled pilot of 10–50 organizations. Six initial roles: Platform Super Admin (PSA), School Admin (SA), Principal (P), Teacher (T), Student (S), Parent/Guardian (G). School types and grade levels are data, not code branches limited to Class 8.

Recommended topology A: one Laravel modular monolith REST API, one unified Next.js TypeScript web application, one Flutter/Dart Android app and one dedicated Edu MariaDB database. Each school is a tenant. Nursery stays operationally separate, including credentials, filesystem, database, app identity, domains and deployment pipeline. Platform administrative routes share a deployment but use distinct backend authorization.

Pilot release includes registration/private demo; account provisioning and guardian links; sessions/admissions/classes/subjects; attendance/corrections; formal notices; homework Done; exams/results; school fees; manual SaaS subscription payments/receipts; timetable draft generation and temporary substitutions; teacher leave; private storage; permissioned reports; notifications; quota/backup/monitoring controls. These ship in milestones, not a single release. Mobile covers all six role dashboards and essential authorized workflows; advanced configuration, bulk previews and platform operations may initially open authenticated responsive web routes with explicit screen-level parity tracking. Mobile delivery is not complete until the listed mobile acceptance journeys pass.

Later, disabled or optional: student homework uploads, chat, online payment gateways, SMS OTP, advanced payroll/HR, automatic emailed reports, imports, extensive certificate templates, advanced optimization and dedicated tenant databases. Optional admission documents and teacher attachments are permitted only once private upload controls pass. Results and admit cards never depend on dues in v1.

Success targets (design targets, not measured claims): zero known critical/high security defects at pilot release; all owner-rule tests pass; cross-tenant access denied for web/mobile/jobs/downloads; no duplicate financial transaction on replay; restore drill achieves RPO ≤24 hours and RTO ≤8 hours; core API p95 ≤800 ms at the documented synthetic pilot mix; 99% non-injected requests succeed; routine read-only mobile journey usable on a 2 GB RAM Android device and throttled 1 Mbps network. Operability and guardian privacy gate the pilot before additional features.

## 02. Evidence and assumptions register

| ID | Classification | Observation and implication |
|---|---|---|
| E01 | OWNER-CONFIRMED | Master prompt v3.0 dated 28 October 2026; preserved verbatim in this package. Requirements take precedence over reference code. |
| E02 | VERIFIED by GitHub connector | `mollanuramin130/Nursery_Management_Project` exists; returned public visibility and admin permission; default branch `development/v1.0.0`. Read-only inspection only. |
| E03 | VERIFIED file | `apps/nursery-api/composer.json`: PHP `^8.4.1`, Laravel `^13.8`, `tymon/jwt-auth ^2.3`; composer file blob `76dfba0129dd7b1b59802508be24b96c9e659362`. These are declared ranges, not installed/runtime versions. |
| E04 | VERIFIED files | Admin and actual customer `apps/nursery-web/package.json` declare Next `16.4.3`, React `19.2.8`, TypeScript `^5`, Tailwind `^4`. Both strict tsconfigs allow JS. Inspected admin `src/app/layout.tsx` confirms actual TSX use. Choose strict TypeScript for new Edu code. |
| E05 | VERIFIED path mismatch | `apps/nursery-customer/package.json` returned 404; README identifies `nursery-web`. Do not reproduce an obsolete path. |
| E06 | VERIFIED file | Flutter `apps/nursery_app/pubspec.yaml`: Dart `^3.10.9`, Provider, Dio, go_router and secure storage. Declared dependencies only; lockfile/runtime not inspected. Omit Nursery payment/e-commerce libraries from Edu. |
| E07 | VERIFIED partial docs | First 75 lines of deployment guide say PHP 8.3+, conflicting with Composer PHP ^8.4.1. Composer/runtime compatibility gate wins; deployment guide is not evidence of current live configuration. Root LICENSE lookup returned 404; skeleton Composer MIT metadata is not proof of whole-repository reuse rights. No code copying. |
| E08 | USER-SUPPLIED | Hosting active to 24 October 2030; prior 763 MB disk use, MariaDB 10.5, backup retention and 20 connections/second report. Account facts not independently checked. Connection rate is not concurrency. |
| E09 | PUBLIC OFFICIAL DOCS | Hostinger currently documents five Node websites for Business/Unlimited, shared process/thread budget and 120-second Node request timeout. This supports A, but does not establish free slots on this account. [H1] |
| E10 | PUBLIC OFFICIAL DOCS | Current Unlimited listing: 50 GB, 600,000 inodes, 3 GB/database; actual legacy plan entitlements and per-vhost settings need hPanel confirmation. [H2] |
| E11 | UNKNOWN | Actual PHP/Node CLI vs web versions, extensions, Composer availability, free Node slots, vhost roots, DB version/limits, cron frequency, backup offsite availability, current load, deployment rights and SSL origins. |
| E12 | ARCHITECT-SELECTED | Laravel 13 family/PHP satisfying ^8.4.1; Next 16 family/strict TS; Flutter stable satisfying Dart constraint. Final supported patch versions and lockfiles selected after vulnerability and host checks, not blindly cloned from Nursery. Node LTS must satisfy selected Next engines and host availability. |

Evidence inspected on 28 October 2026. No Hostinger account session was opened and no Nursery production API was called. GitHub file blob identifiers are evidence identifiers, not Edu commits. Repository URL: https://github.com/mollanuramin130/Nursery_Management_Project .

## 03. SRS and traceability

`02_REQUIREMENTS_COVERAGE.csv` preserves every nonempty source paragraph in sections A–AF with source line and requirement ID. This is a paragraph-level source coverage inventory: sentences may contain multiple obligations. `03_FEATURE_CONTRACTS.csv` expands operational features into actor, authorization, workflow, tables, API, screen, failure, history/audit, tests and operating controls. Process-only requirements intentionally have no runtime table or endpoint. A mapped requirement is designed, not implemented or tested.

Business invariants:

1. Authentication identity is global; authorization membership is tenant-local. Explicit tenant context is necessary on every school request.
2. Registration review, dashboard access, data stage, demo time, entitlement and payment verification are separate state dimensions.
3. Profiles and enrollment can exist without accounts. P may admit a student; only SA provisions/activates school logins. PSA authorizes initial school administrator. Applicant access is a restricted provisional administrator, not an unrestricted live account.
4. Active school normal writes require valid live entitlement or valid private demo entitlement. Valid entitlement may be free; payment is not a prerequisite. Expiry blocks all ordinary writes across clients and jobs. Suspension may block reads too under a separately logged incident policy; it is not expiry.
5. All records use immutable internal IDs; display admission numbers are tenant-unique and versioned. Historic enrollment/actor/policy snapshots survive future changes.
6. No role configuration can defeat tenant checks, financial immutability, login-provisioning boundaries, teacher notice review, attendance after-hours approval or disabled student uploads.
7. School fees and platform subscription payments have separate tables, receipt sequences, permissions and reconciliation.
8. School quotas constrain files, not row creation. Subscription expiry independently constrains writes. Over-quota conversion never deletes files.
9. Parents cannot self-link. Guardian relationship revocation removes current access immediately, including cached views/downloads and outstanding export retrieval.
10. Expired schools retain authorized historical reads subject to reviewed retention policy, not indefinite retention guarantees.

Nonfunctional controls: UTC timestamps plus school IANA timezone; server-derived local dates; UTF-8/utf8mb4; INR amounts as integer paise; non-INR deferred; ISO date APIs; cursor pagination default 25/max100; private no-store responses for child/financial data; request IDs; validation allowlists; optimistic `version` checks; transactional changes; bounded jobs and exports; accessibility WCAG 2.2 AA target; English launch with Bengali/Hindi translation keys.

## 04. Capability and approval matrix

Notation: own = own record; linked = verified children; assigned = effective class/subject/date scope; school = current tenant. Blank/deny means unavailable. Every grant remains capped by fixed security rules and entitlement. SA can configure permitted academic/report grants for P/T but cannot delegate account creation to P/T or platform powers.

| Operation | PSA | SA | P | T | S | G |
|---|---|---|---|---|---|---|
| Approve school / initial SA | platform | deny | deny | deny | deny | deny |
| Provision/activate/reset school accounts | initial SA only | school, verified identity | deny | deny | own password only | own password only |
| Admission profile create/update | support grant only | school | school if permitted | scoped read | own read | linked read/contact request |
| Configure class/session/subjects | templates only | school | allowed operational view/use | assigned read | own read | linked read |
| Change active session boundaries | approve request | request/preview | view | view | view | view |
| Permanent staff status/assignments | deny | school only | deny | own read | deny | deny |
| Timetable draft/generate/publish | deny | school | school | assigned read | own read | linked read |
| Temporary substitute | deny | school | school | assigned read | timetable read | timetable read |
| Attendance create/amend before 16:00 | deny | school with audit | overview; no teacher edit by default | assigned/date | own read | linked read |
| Attendance after cutoff correction | deny | approve/direct with reason | deny approval | request only | read | read |
| Notice draft/submit | platform announcements | school | school | assigned | read published | read published |
| Approve teacher notice | deny | school | school | deny | deny | deny |
| Publish authorized own notice | platform scope | school | school | deny | deny | deny |
| Homework assign/review | deny | school policy | school policy | assigned | own done mark | linked read |
| Student homework attachment | deny | deny on student's behalf | deny | deny on student's behalf | disabled | disabled |
| Marks enter/submit | deny | school | permitted school | assigned | deny | deny |
| Results review/publish | deny | school | school | deny | own published | linked published |
| Missing-mark exception | deny | reason/confirmation | deny | deny | deny | deny |
| Fee policy / adjustments | deny | school | no policy; limited corrections | deny | optional own read | linked read |
| Fee collection / correction | deny | school | audited limited | deny | deny | deny |
| Financial export | platform billing only | school | explicit grant | deny | own permitted | linked permitted |
| SaaS terms/payment approval/quota | platform | own view/proof request | generic expiry warning | deny | deny | deny |
| Guardian links / permission changes | deny ordinary PII | school verification | deny link changes by default | deny | deny | request only |
| Teacher leave approve | deny | school | school | own request | deny | deny |
| Certificate issue/release (later) | deny | school signatory | delegated signatory | deny | released own | released linked |
| Support PII access | approved expiring support session | approve/revoke support | deny | deny | deny | deny |

Prevent removing/deactivating last active SA; serialize membership changes per school. One school role per membership initially, multi-school memberships allowed. No combined role union silently across tenants. Elevated PSA identity uses separate platform context, MFA baseline and no automatic school PII privileges. Support requires school consent, purpose, capability subset, 30-minute maximum baseline and audit; break-glass process remains a material approval item.

Activation: create profile → SA selects identity/link → random provisional secret or hashed single-use email activation token (30-minute baseline) → verified delivery/assisted handover → force new password → revoke provisional sessions. No plaintext storage, predictable DOB passwords, secret download lists or production credentials in handoffs. Student login uses school code + rotatable visible Student ID + password. Parent/staff mobile or email login only after verification, with ambiguous shared phone lookup requiring school/assigned ID. Login errors are generic. Identifier rotation updates history and revokes sessions. Privileged authenticator MFA is a pilot baseline; no SMS dependency.

## 05. Modules, dependencies and journeys

Modular API domains: TenantOnboarding; Identity; Academic; Student; Attendance; Homework; Exams; Fees; SubscriptionBilling; Notice; Timetable/Leave; Storage; Reports/Documents; Notification; Audit/Operations. Each domain owns its writes and transaction boundaries; use services/events for cross-domain work, not uncontrolled model writes. Eloquent repositories only where query complexity justifies them.

Identity + TenantContext + Entitlement are prerequisites for school commands. Academic + Student feed Attendance/Homework/Exams/Fees/Timetable. Storage owns all object lifecycle operations; it never infers academic access without resource policies. Notification consumes transactional outbox events; it cannot mutate grades/fees. Reports read authorized projections and do not become an alternate write path. Audit is append-only for business actors, with separately authorized retention maintenance.

Registration journey: submit essentials + notice acknowledgement → opaque tracking token/status → verified applicant provisional context → private 30-day demo → SA readiness preview → PSA registration approval + access activation + explicit free/trial/paid entitlement → SA finalize validated real demo records. Support call/WhatsApp uses centrally managed public contact links; the app does not send messages automatically.

School journey: configure future session/classes/subjects → admission profiles/guardian verification → SA login handover → teacher assignments → attendance/homework/notices → exams → publish → school fee collections → renewal evidence. Parent switches only linked children and retained sessions. Teacher sees assigned daily work, 16:00 warning and correction requests. PSA sees operational aggregates and billing, not children's records.

## 06. State machines and transaction rules

| Aggregate | States / commands | Guard, failure and historical behavior |
|---|---|---|
| Registration | submitted → reviewing → approved/rejected; reopen with reason | PSA only review. Public tracking shows generic state, no child data. Duplicate suspicion routes to review, never silently merges schools. |
| Access | provisional / live_enabled / suspended / archived | Pending registration permits provisional demo only. Live requires approved registration; suspension explicit reason/capabilities. Archive is not hard deletion. |
| Data stage | demo → readiness_preview → live | Preview token binds tenant, candidate IDs, versions, sample exclusions and expiry. Finalize atomic under school lock; retry same idempotency key returns same result; stale preview 409. Excluded/sample/incomplete rows remain inaccessible to live workflows until reviewed; no accidental new student logins. |
| Entitlement | draft → scheduled → effective → expired/revoked | Type free/trial/manual_paid/extension; effective interval half-open [start,end). Server calculates now; missed cron cannot extend writes. Free grants valid independent of payment. |
| Demo | enabled + start/end; disabled/expired | 30 days baseline elapsed duration; server-derived remaining days. Disable stops demo operational writes. After demo expiry limited reads/renewal/support only unless suspended; this read baseline is not indefinite retention approval. |
| SaaS payment | submitted → verifying → approved/rejected; correction/refund document | Unique normalized receiving-account + bank reference; verify actual money externally. Approval locks row + sequence, appends immutable receipt + entitlement amendment + outbox. PDF render failure retries same receipt number, never re-approves money. Terms/amount/date snapshot required. Free extension produces grant confirmation, not paid receipt. |
| School fee | obligation posted → partially/fully settled; reversal/credit/refund | Append events and allocations; never edit posted financial events. Correction references original; due = charges − adjustments − net allocations. Refund/credit explicit and cannot create unbalanced totals. |
| Academic session | future → active → closed → archived | SA can change future boundaries after overlap checks. Active date change request → PSA preview/review/approve. Old enrollment references unchanged; approved changes update calendar rules prospectively. |
| Promotion | draft preview → confirmed → applied; corrective enrollment action | Target session uniqueness and eligibility; retained/transferred/withdrawn exclusions shown. Bulk operation staged as job with atomic per-student outcomes and resumable result manifest, not claimed global atomicity. Undo only if no dependent target events; otherwise authorized corrective history. |
| Attendance | draft → submitted → revised before cutoff; correction_requested → approved/rejected | T edits only on attendance date and server local time <16:00. At exactly 16:00 require SA approval. Future dates denied; late first submission is permitted for assigned teacher but immediately locked, audited and cannot disguise correction. Request binds old version; stale approval 409. |
| Notice | draft → submitted → approved/rejected → scheduled/published → withdrawn/corrected | P OR SA approval suffices for teacher revision; approved revision hash must match published revision. Self-authored SA/P may publish directly. Post-review edits reset approval; withdrawn remains historical, no raw delete. |
| Homework | assigned → done / not_done; review verified/reopened | S can change own declaration until deadline +24h baseline; T review is separate. G read only baseline. Disabled student upload always denied by API. |
| Exam result | draft → submitted → reviewed/approved → scheduled/published → amended | Required-mark completeness, valid ranges and grading policy version checked at publication. SA exceptional missing-mark override scoped and reasoned; do not fabricate absent marks. Published snapshots immutable. Scheduled publisher rechecks entitlement. |
| Timetable | generation_requested → draft → conflict_checked → approved/published → superseded | Bounded heuristic may return partial draft with conflicts; hard teacher/section/resource overlap blocks publish. Manual changes same checks. Time ranges checked transactionally under schedule lock. |
| Leave | requested → approved/rejected/cancelled | P/SA review; no self-approval if actor also teacher in another context. Substitute effective for dated periods; original template unchanged. No parent/student leave or substitution events. |
| Document (later) | requested → reviewed → authorized → issued → released → superseded/void | SA or delegated P signatory, numbered immutable PDF with template/version snapshot. Parent sees released copy only. Dues cannot block admit cards/results. |
| File | reserved → quarantined → validated/available → retained_version → erasure_pending → purged | Every stored byte counted; failed temporary bytes count globally until cleanup. Download rechecks tenant/resource/guardian authorization. Quota reduction preserves existing reads. |

Derived policy precedence: authenticated context → resource authorization → suspension/access → entitlement/write classification → feature cap → operation state/version → file/global reservation → transaction/outbox. Reads still require resource policy. Expired renewal proof submission and security/support workflows are a narrow command allowlist; quota proof uploads have a bounded platform-funded reservation so renewal cannot be blocked by school quota. They still consume global physical disk and receive rate/size limits. No broad allow-all POST exception.

Parent absence event on first authoritative absent state creates per-guardian in-app recipient records in the same transaction as attendance/outbox. Correction absent→present creates a separate event. Uniqueness is (school, attendance_record, transition_version, event_type, recipient, channel). Retrying a single transition cannot duplicate delivery; a later genuine distinct absence transition is a separate auditable event. Invalid/revoked guardian links are excluded at event time and rechecked at delivery/read time; new links do not automatically receive old notifications. Historical academic visibility defaults to retained child sessions after current verified guardianship, subject to link scope/restrictions verified by SA. Keep effective link history and access revocations.

## 07. Tenant security, privacy and data flows

Web/mobile sends authenticated command with membership context → Laravel resolves identity and active membership → policy loads tenant-scoped aggregate → service verifies entitlement/state/version → scoped DB transaction writes business record + audit + outbox → bounded delivery/export job rechecks authorization → private file download rechecks resource policy. User-supplied school_id is never authoritative. Use explicit tenant context objects in all services and jobs; no silent default tenant and no shared singleton state across requests.

Every school table has NOT NULL school_id plus `(school_id,id)` unique key. Every school-to-school foreign reference uses `(school_id, parent_id) → parent(school_id,id)`. No cross-tenant polymorphic resource references without typed relation validators. Use restrictive foreign keys for historical records; no cascade deleting student/enrollment/financial history. MySQL-compatible databases do not provide an assumed row-level-security safety net: global scopes are convenience, policies plus service scope and composite FKs are required. Platform tables are explicit and use distinct service authorization.

| Threat | Prevention | Detection / response |
|---|---|---|
| BOLA/IDOR and exports | Tenant-first query, memberships, typed resource policy, composite FK | Tenant A/B adversarial tests, request/audit correlation, revoke affected sessions |
| Stolen admin credentials | Argon2id where runtime supports calibrated cost; secure bcrypt fallback, MFA, throttle, rotation | Failed-login counters, privileged action alerts; incident access freeze |
| Fake school/guardian | Verified applicant contact, throttled provisional access, SA relationship verification | Review queue, duplicate flags, revoke disputed links immediately |
| Payment proof forgery/replay | External settlement verification, unique bank ref/account, immutable ledger | Reconciliation + audit; compensating correction, not receipt overwrite |
| Child data/file leak | Nonpublic storage, no-store, reauthorization at download, minimal DTO fields | Access logs without contents, incident scoping and owner-led notification process |
| Upload execution/decompression | Allow PDF/JPEG/PNG, sniff MIME, pixel/page/size bounds, random paths, no SVG/archives/scripts | Quarantine; local scanner if executable available, otherwise optional uploads held disabled until safe release process approved |
| Expiry bypass | Service-level write gate and execution-time job check | API matrix includes imports/schedulers/background work |
| Insider PSA browsing | Time-bound school-approved support capability; no universal PII index | Access audit, school-visible support history, immediate revoke |
| CSV/XSS injection | Escape output/HTML, CSV formula-safe text, CSP, no untrusted active docs | Export fixtures and template review |
| Shared-host compromise | Separate app credentials, vhost roots, keys outside public roots | Backups, secret rotation and restore; account-level OS isolation from Nursery is not guaranteed on one hosting account |

Private child/health/payment data stays out of Git, previews, AI handoffs, analytics and log bodies. Sensitive optional fields require purpose and retention classification. Application field encryption uses versioned keys and encrypted backups; keys stored separately from public root and backed up through a separately controlled owner channel. Encryption cannot prevent a compromised app process reading its own authorized plaintext. Avoid collecting Aadhaar or optional health records in pilot by default.

Legal design gate: privacy notice versions; school/platform responsibility allocation; guardian verification/consent evidence where required; purpose inventory; access/export/correction/erasure requests; retention classes; legal hold; deletion approval with backup expiry mapping; incident communications owner/contact; subprocessors and hosting location review. Gazette G.S.R.843(E) uses staged commencement, including an eighteen-month phase for sections including children's processing provisions. Do not equate publication of rules with every provision being in force on this design date. Counsel must verify current applicability, educational exceptions, commencement dates and operational duties before real minors' data pilot. No compliance certification is claimed. [L1, L2]

Baseline expiry does not erase records. Retention durations for academic history, proofs, health data and consent are intentionally **not** invented: data category policy needs legal/owner approval before real-data launch. Synthetic development can proceed. Audit baseline 90 days online with encrypted archive proposal; no automatic deletion of legally significant audit/financial records until retention approved. Support break-glass and guardian disputes are material privacy decisions.

## 08. Relational model, dictionary and migration design

See `04_DATA_DICTIONARY.csv` and `09_RELATIONSHIPS_AND_FLOWS.md`: normalized table inventory with key attributes, types, scope, constraints and retention class. Shared conventions: ULID CHAR(26) ASCII/binary-collated internal IDs, UTC DATETIME(6), DATE for school date, integer BIGINT paise/bytes, bounded VARCHAR codes, explicit status validation and unsigned integer row version. Every mutable record has created_at/updated_at and creator; append-only records use recorded_at/actor plus supersedes/reverses pointer. Nullable values are explicitly marked `?`; omitted fields are required. PII classification and actual field encryption finalized in migrations from this dictionary. No application migrations are executed in this gate.

Core relationships:

- schools 1:N memberships, sessions, students and entitlements; users 1:N memberships and identifiers.
- students 1:N enrollments; sessions 1:N class_offerings; enrollment references session + section; section references a class offering, thereby preserving session/grade shape.
- guardians are school-scoped profiles; guardian_student_links connect profiles to students with verification/effective interval; optional membership binds login to guardian profile.
- staff assignments link school membership, section and subject offering over date range; membership must belong to staff role. Timetable and substitute relations keep original author/template.
- attendance_records reference enrollment/date and immutable attendance_events/correction requests. Event actors reference memberships rather than mutable teacher names.
- exams + grade_policy_versions → marks_versions → result_versions; publication references immutable policy and subject snapshot, not recalculated current rules.
- school fee obligations/events/allocations/receipts use school namespace; platform payments/grants/receipts use distinct platform tables and beneficiary school.
- private documents → document_versions → storage_objects; bindings reference permitted typed academic resources. Every object download resolves binding then authorization. Quota ledger includes retained versions and reservations.

Critical indexes: students `(school_id,admission_number)` unique; enrollments `(school_id,student_id,session_id)` unique initially, multi-track deferred; attendance `(school_id,enrollment_id,attendance_date)` unique; marks `(school_id,exam_subject_id,enrollment_id,revision)` unique; homework completion `(school_id,homework_id,enrollment_id)` unique; payment reference `(receiving_account_id,normalized_reference)` unique; receipt `(issuer_id,financial_year,sequence)` unique per ledger; notification `(school_id,event_id,recipient_membership_id,channel)` unique; operational indexes `(school_id,session_id,status,id)` on matching queries, not blindly every table. No unique phone across all people; shared phone ambiguity handled explicitly. School code changes preserve alias history, do not rewrite foreign keys.

Financial amounts cannot be floating-point. Payment allocation and refund services lock relevant obligation/event rows; sum conservation checked before commit and by reconciliation. Receipt sequence is a dedicated locked counter scoped to issuer/year; no `MAX()+1`. Failed PDF rendering doesn't consume a new payment event. Issued payment receipt is not automatically a tax invoice; legal issuer/tax fields await review.

Schema evolution: additive column/index migration first → backfill bounded tenant batches with checkpoint/version checks → compare counts/constraints → dual-read/write only if documented → switch consumers → remove old schema in later compatible release. Test against actual compatible MariaDB, not SQLite alone. Reversible means expand/contract and data preservation; a destructive down migration is not safe rollback. Restore fallback requires consistent DB/files snapshot and traffic pause. No destructive migration or live data experiment without authorization.

## 09. REST API v1, auth and contracts

`05_OPENAPI.json` is the proposed machine-readable OpenAPI 3.1 contract. Endpoint catalogue includes per-operation capabilities, tenant scope and entitlement class. Request schemas enumerate fields; backend forbids unknown fields by default, including client school_id ownership overrides. It is a design contract, not generated from live endpoints. Contract validation performed separately; no client SDK has been generated or tested yet.

Base path `/api/v1`. Standard response `{data,meta:{request_id,version}}`; list meta includes next_cursor/has_more; errors `{error:{code,message,fields},meta:{request_id}}`. Times ISO8601 UTC, school date YYYY-MM-DD; money integer paise. 401 unauthenticated; 403 authenticated but forbidden/disabled/read-only; 404 for foreign/nonvisible resource; 409 state/version/idempotency conflict; 422 field validation; 413 upload too large; 429 throttled; 503 temporary capacity. Do not reveal whether foreign tenant IDs exist. Successful async jobs return 202 + authorized job ID/status endpoint. Binary downloads have content-disposition and private/no-store headers.

Critical commands require `Idempotency-Key`, tenant+actor+operation+key unique, canonical body hash, stored result/resource pointer. Same key/different body 409. Retain financial command keys linked to ledger long-term under approved retention; nonfinancial baseline 7 days plus persistent domain uniqueness. Optimistic version in request; update succeeds only matching version. All mutation capability checks are server-side; UI capabilities are hints.

Browser baseline: Sanctum first-party stateful session, secure HttpOnly host-only API session cookie, exact trusted web origin/CORS credentials, CSRF handshake and token; session storage in Edu DB. Host-only cookies avoid sharing auth with Nursery sibling hosts; explicitly return CSRF token only to allowed frontend origin where cross-subdomain readable cookie is unavailable, and verify Laravel middleware integration in an integration spike. Do not broaden cookie scope to `.nuvikoz.com` to make CSRF convenient. Use selected Edu-only same-site origins; cross-site deployments require reviewed cookie/CSRF architecture. Session inactivity baseline 30 minutes staff/8h parent-student with 12h maximum; reauthenticate critical admin changes. [A1]

Mobile: opaque revocable Sanctum tokens stored only in OS secure storage, per-device 7-day expiry baseline and online reauthentication; privileged tokens 12h baseline. No reusable token in URLs, logs, localStorage or shared preferences. Rotate on reauthentication/credential change, revoke on membership/identifier/password changes. Membership selected via server-verified context; token possession alone does not assert a school. No custom refresh-token protocol in v1. Optional remember-me requires later deliberate policy, not indefinite tokens.

Sample attendance command: `POST /attendance/submissions` with `{section_id,date,items:[{enrollment_id,status}],version}`; teacher assignment/date verified; atomically persist submission and per-student records/events/outbox. Repeated batch identical key returns prior result. Partial unauthorized enrollment rejects entire batch; no mixed-tenant partial commit. School API `GET /students?cursor=...` paginates scoped data, never accepts cross-school ownership assignment.

## 10. UX, routes, design system and Flutter

One app deploy with route layouts `(public)`, `(platform)`, `(school)`, `(teacher)`, `(family)`; actual URL prefixes below. Tenant context selected outside route-group names; role-prefixed URL is not authorization.

| Actor | Routes / primary journeys |
|---|---|
| Public/applicant | `/register`, `/registration-status`, `/login`, `/activate`, `/recover`, `/choose-school`; support call/WhatsApp, privacy acknowledgement and demo countdown |
| PSA | `/platform/{dashboard,registrations,schools,entitlements,payments,receipts,quotas,policies,resources,backups,support-access,audit,settings}` |
| SA | `/school/{dashboard,readiness,students,admissions,accounts,guardians,staff,sessions,classes,subjects,assignments,timetable,attendance,approvals,homework,exams,fees,notices,reports,documents,storage,subscription,settings,audit}` |
| Principal | `/principal/{dashboard,admissions,timetable,substitutes,attendance,approvals,exams,fees,notices,reports,leave}`; no account provisioning |
| Teacher | `/teacher/{today,classes,attendance,corrections,homework,marks,notices,timetable,leave,reports}` |
| Student | `/student/{home,attendance,timetable,results,homework,notices,documents,profile}` |
| Parent | `/parent/{children,overview,attendance,results,homework,fees,receipts,notices,documents,contact-requests}` |
| Shared | `/notifications`, `/profile/security`, `/session-select`, `/access-status`, `/jobs/{id}` |

Design tokens: deep navy text #172B4D, teal primary #087F8C, white/off-white surfaces; validate actual combinations for AA contrast, never rely on color alone. 8px spacing rhythm, 16px minimum body, ≥44px touch targets; readable local bundled fonts including Bengali coverage. Layout: context header with school/session, role-specific navigation, prominent Demo/Expired/Over-quota banners, one primary action per flow. Amounts/identifiers align for easy checking. Accessible keyboard, focus, error summaries, labels and table alternatives on phones. No decorative charts replacing actionable daily tasks.

Reusable components: capability-aware action, private document viewer, status badge, confirmation/preview, version-conflict dialog, pending approval queue, pagination, masked table/export selector, money input, date/session picker, child switcher, receipt preview, quota meter, audit timeline, retry panel, empty/loading/error states. Expiry screen explains historical reads and permits SA renewal proof; commercial detail hidden from teacher/family; P sees generic warning. 16:00 attendance warning displays school timezone. Missing marks show exact subjects; overrides demand SA reason/confirmation. Dues visible without result lock.

Flutter: feature folders with models/DTOs, Dio client, repositories and Provider view models, go_router guards, secure token store. DTOs derived/reviewed against same OpenAPI; domain permissions remain on API. Clear in-memory child/tenant cache on switch/logout/revocation. Initial offline behavior: transient connection feedback and unsent attendance draft in memory only; no durable child-data cache or silent offline mutation queue. Warn draft may be lost on close. No offline payment approvals. Android low-memory/rotation/process-death/network-expiry tests required. Optional push adapter stays disabled until provider/project/privacy checks; in-app history always works. Version header and `/client-policy` support minimum app/API compatibility; additive schema changes tolerated, security-required forced update path reviewed.

Mobile parity plan: M1 auth/registration/private demo; M2 own/linked history and staff admissions read/create within policy; M3 teacher attendance/homework/notices/marks/leave; M4 SA/P approvals, school fee entries and renewal proofs; M5 platform queues/approvals responsive native essentials, advanced configuration via trusted web login. Track each URL journey in acceptance evidence; never call a web-only journey native-complete.

## 11. Deployment candidates and Hostinger verification

| Candidate | Runtime additions | Benefits | Costs / rejection condition |
|---|---|---|---|
| A recommended, conditional | 1 PHP API site + 1 Node web site; APK no web slot | Shared layouts/contracts; one web release and idle process; route bundles lazy-loaded | Measure Node RSS/threads + combined traffic; unified process is common failure domain. Not validated on account yet. |
| B alternative | 1 PHP API + 2 Node web sites + APK | Independent web scaling/release; student load isolation | Extra idle memory/builds/processes; choose only if measured A saturation and enough headroom show B improvement. Not automatically cheaper/faster. |
| C fallback | 1 PHP API + static exported Next assets, no persistent Node runtime | Lower process demand; authenticated API features preserved | No SSR/server actions/dynamic server-only routes. Use fixed shell pages with query-selected resource IDs and client fetch, so arbitrary IDs need no static generation. Test deep links/static rewrite, cookie/CSRF, logout and no PII in generated HTML. [N1] |

Public H1 documents five Node sites and shared threads/processes. A adds one Node site, not one per role; PHP has separate website provisioning but consumes shared resources. Confirm actual counting in hPanel and existing www/apex behavior; canonical Edu hostname avoids accidental duplicate runtimes. No change to Nursery domains is needed. Build artifacts in CI/local; never run in-place dependency builds on live shared files if they endanger service. No Docker/Redis/Supervisor/custom sockets required.

Verification checklist (unknown until account evidence): plan ID/legacy limits and remaining Node sites; list existing PHP/Node websites; PHP FPM and CLI 8.4.1+ and extensions; Node supported LTS and package engines; MariaDB actual version/SQL modes; DB per-user simultaneous connection limit vs connection-rate report; cron minimum interval/locking; private path outside public root; PHP upload config per Edu vhost; route rewrite/SSL/cookie origins; selected Node startup/deploy monorepo root; Node threads/RSS baseline for one hostname; PHP worker and process peak including Nursery; storage/inodes; backup file+DB retention/download; safe isolated staging. Never collect passwords in documents. No production activation until this checklist and a staging rehearsal pass.

Measure A/B in isolated staging after deployment authorization: 15-min idle baseline, 30-min warm-up, 60-min representative run, same build/data/concurrency, compare p95 API, errors, RSS/PHP workers, NPROC/threads and Nursery-independent account resource aggregate. B only accepted if reduced risk/performance improves without exceeding headroom; otherwise A or C. No benchmark has run. C compatibility spike is mandatory before claiming fallback deployable.

## 12. Quota, physical capacity and operations

Quota display baseline resolves ambiguous units explicitly: live 500 MB = 500,000,000 bytes; demo 1 GB = 1,000,000,000 bytes (decimal), configurable by PSA. Warn ≥80%; reject upload whose stored bytes + live reservations + proposed bytes exceed quota; at exact quota new positive upload fails. Existing objects remain readable. Atomic school quota row lock + global reservation lock; reserve upper bound before receiving, validate/resize, settle actual usage then release surplus. Failed uploads reconcile expired reservations and quarantine/temp bytes. Versions count until lawful purge. Do not deduplicate across tenants; avoid subtle ownership leaks.

Upload limits baseline: JPEG/PNG 2 MB, PDF 5 MB, payment proof 2 MB; maximum 5 files/request. Decode with pixel bound 20 megapixels, strip metadata; PDF page bound 100, deny encrypted/active content without safe processing. If reliable local scanner unavailable, keep general optional uploads disabled pending approved manual quarantine/scanning path; core workflows remain text/data-driven. Generated PDFs reserve space too; critical receipt metadata/numbering persists on render failure and can render on demand with bounded temporary spool. Renewal proof has separate small platform reserve, not unlimited quota bypass.

Proposed **planning budget**, not account measurement, within reported 50 GB: Nursery/current + growth reserve 5 GB; Edu code/releases/assets 3 GB; database provision/index headroom 3 GB; logs/tmp 2 GB; incident free space 7 GB; local backup staging 5 GB; tenant files 25 GB. Sum 50 GB. Provider DB accounting/disk units may differ; validate dashboard before allocations. Remote provider backup space must be checked separately. If full local file backup needed, 5 GB staging cannot hold 25 GB: stream encrypted backup off-host with approved destination, or staged chunks plus manifest; no false full-copy promise.

50 live schools × 500 MB = 25 GB quota; 50 demos × 1 GB = 50 GB before overhead, therefore unsafe to admit every potential demo at full guarantee simultaneously. PSA controls admission capacity and active demo count with total reserved quota accounting: live quota reservations + demo reservations + protected overhead ≤ capacity. Example: 30 live +10 demos =25 GB. A 50-live pilot needs demos staggered, reduced active trial concurrency with transparent policy, or approved extra storage. Never silently change an individual confirmed demo quota. Quota is a promised allocation policy, not proof of current disk availability.

DB sizing assumption: 50 schools × 200 students × 220 attendance days =2.2m attendance rows/year. At assumed measured-later 250 bytes/row with indexes, ≈550 MB/year for attendance only; event/correction/audit overhead may multiply this. At 500 students/school attendance alone ≈1.375 GB/year. These are arithmetic scenarios, not MariaDB benchmarks. Capture actual bytes after 100k synthetic rows, include index/data/free space and all tables; 3 GB cap is a growth gate, not capacity for arbitrary history. No fixed simultaneous user guarantee. Move DB/object storage or dedicate tenants using routing registry only after measured need and approved cost.

Queues: transactional database outbox + database queue, cron every minute if supported. Bounded one-shot worker ≤40 seconds, job timeout ≤20 seconds, reservation/retry timeout > worker timeout, stop when empty/max jobs; DB lease/advisory locking prevents overlapping cron. Use framework-supported switches verified at implementation. Prioritize notifications/security over PDF and generation. Retry backoff 1m/5m/15m; fail after five attempts baseline; no lost business event. Runtime handler rechecks entitlement except explicit security/renewal/notification-delivery allowlist. Dead-letter dashboard + audited replay. No hard real-time delivery promise. [Q1]

Notifications: in-app persistent events first; polling while active with backoff, default 60s staff /120s family; no polling hidden tabs. No leave/substitution recipient templates for family. Optional FCM can carry generic event ID only, no lock-screen PII; verify project terms, quota, device consent, retention and cost before enablement. Service exists but this project is not configured or load-tested. [F1] SMTP adapter replaceable; low-volume authenticated Gmail only when credentials/terms configured; no deliverability/free-volume guarantee. No paid SMS/payment provider mandatory.

Monitoring baselines: warn DB/disk/inodes ≥70%; critical ≥85%; deny noncritical new reservations if projected global disk free <15% or DB approaching verified cap; individual school warn80/block100 independently. Warn PHP/process saturation >70% sustained5m, queue oldest >5m, cron missing >3m (adjust to verified interval), API 5xx >1%/5m, backups stale >26h. These are proposed thresholds, tune using observation. Log request ID/tenant opaque ID/action/status/duration, never passwords/tokens or payloads. Rotate diagnostic logs daily baseline; failure history retention reviewed; aggregate monitoring polling every5m to avoid undue load. Owner dashboard alarms are mandatory; external pager optional and permissioned.

Backup runbook: verify provider snapshot coverage and retention; daily DB + file manifest backup, same recovery point by brief write pause or coordinated snapshots/versioned immutable objects. Encrypt and checksum; archive keys under separate owner control; nonpublic path; off-host backup destination/permissions must be verified or approved. RPO24h/RTO8h remain unproven until isolated restore drill checks row counts, receipt balances, file hashes, guardian scope, entitlement and queue dedupe. Do not replay already delivered notifications after restore. Identify restoration epoch/outbox replay watermark. Production restore is destructive and needs explicit authorization.

Incident runbook: record discovery → restrict affected scoped writes/sessions → preserve access/audit evidence → assess children/financial exposure → rotate scoped secrets → restore/fix in isolated environment → owner/legal reviews notification duties → staged release → postincident corrective actions. Emergency read-only mode enforced server-side; credential reset and incident actions still work. No log cleanup destroying evidence. Safe deployment: preflight backup, compatible migrations, release directory switch, health/synthetic smoke, rollback code if compatible; if incompatible stop writes and follow approved restore, never blindly migrate down.

## 13. Test and acceptance strategy

`06_TEST_MATRIX.csv` is a concrete proposed regression suite keyed to feature contracts. All application tests are **NOT RUN — no application exists at this gate**. Architecture checks are separate in `08_REVIEW_AND_VALIDATION.md`.

Fixtures: entirely synthetic School A/B/C, same visible admission IDs across tenants; users with mixed memberships and duplicate phone; last-admin; revoked guardian; future/closed sessions; assigned and expired teacher; missing marks; fractional fees/overpayment/reversal; quota just below/at/over; demo 750 MB; expired/free/suspended school; malicious MIME/path/CSV inputs. Freeze server clocks around Asia/Kolkata 15:59:59/16:00:00/date rollover and test another timezone. No real minors or production backups in CI.

Layers: unit policy/state/calculation tests; API integration on MariaDB; adversarial tenancy on all resource endpoints/jobs; schema/OpenAPI and TS/Dart compatibility; Playwright role journeys; Flutter widget/integration; accessibility keyboard/contrast; private file and CSV/PDF content assertions; queue timeout/replay; migration + restoration rehearsal; synthetic load. Guard critical permission paths with mutation testing/manual negative review where feasible; code coverage alone is not security evidence.

Proposed commands after implementation: API `composer install` from lock, `vendor/bin/pint --test`, `php artisan test`; web `npm ci`, `npm run lint`, `npm run typecheck`, `npm run test`, `npm run build`, `npx playwright test`; Flutter `flutter pub get`, `dart format --output=none --set-exit-if-changed .`, `flutter analyze`, `flutter test`, device integration and debug build; OpenAPI pinned validator and contract test. Scripts must be created before claiming these commands exist/pass. CI never auto-deploys production. MariaDB services and fake mail/push/disk adapters isolate tests.

Load plan: staged 10/25/50 tenants; enrollment cases200/500 per school; active concurrency10/25/50 as experiments, not contracted capacity. Mix70% authorized reads,20% attendance/homework writes,5% report polls,5% misc including renewals; separate fee/PDF/timetable bursts. Ramp gradually; run fixed60m after warm-up; record p50/p95/p99, timeouts, queue lag, RSS/threads/PHP/DB limits, indexed query plans and disk growth. Stop if shared account safety thresholds reached; no production stress or scans authorized. Report sustainable measured envelope and upgrade at70% sustained capacity or projected DB80% within90days.

Release gates: all owner-rule/tenancy tests pass; no unexplained flaky security tests; critical/high issues resolved; load objectives achieved or scope reduced transparently; restore targets demonstrated; host evidence and legal retention/privacy setup reviewed; manuals available; separate production authorization. User acceptance: SA admits/provisions independently, P profile-only admission, T after-hours correction route, family history and no file upload, free live access and proof verification, over-quota transition, published result access regardless of dues.

## 14. Repository, CI, handoff and initial backlog

Proposed private repo `NUVIKOZ_Edu`; likely owner `mollanuramin130` based on verified Nursery owner, but account/organization/visibility/branch settings must be explicitly verified at Gate3. No repository has been created. Proposed main protected branch, feature branches, reviewed PRs, required checks, no force pushes and no unapproved production workflow. Branch protections availability for private repository/account plan is unknown; if unavailable document equivalent review procedure rather than promise enforced rules.

Planned folders: apps/edu-api; apps/edu-web; apps/edu-mobile; packages/contracts; docs/{requirements,architecture,database,api,security,ui-ux,testing,deployment,decisions,agent-handoffs}; scripts; tests; .github/workflows; README; AGENTS; CONTRIBUTING; CHANGELOG. Use per-app package tools and committed lockfiles, no mandatory cross-language monorepo orchestrator. Root docs index points to current contracts. Commit sanitized `.env.example`, no real .env, signing keys or personal records. CI jobs scoped by paths but shared contract changes run all client checks; protect action versions/dependency provenance and secret-scanning checks.

Agent protocol (future): read AGENTS/README/CURRENT_STATUS/active milestone/ADRs/contracts; inspect git status/branch and migrations; bounded task+acceptance; no unrelated refactor; exact commands/results; update status with local timestamp, files, migration effects, blockers and next safe task; update API/dictionary/SRS for behavior drift; commit reviewed feature branch. Never invent commit/repo/test success, overwrite another agent's changes or force push. ChatGPT/Cursor/Gemini resume from repo evidence, not chat memory. This review was prepared by one coordinator using discipline-based checks; no claim of independent human/legal/security review or parallel agent certification.

Initial backlog (not created as GitHub issues): EDU-001 host/runtime evidence;002 reference/license/dependency audit;003 privacy/retention/issuer policy;004 monorepo/CI/contracts;005 tenant identity/auth;006 registration/demo/state gates;007 sessions/classes/students;008 guardian accounts/permissions;009 attendance/notifications;010 notices/homework;011 exams;012 school fees;013 subscription manual billing;014 quotas/files;015 timetable/leave;016 reports;017 web role journeys;018 Flutter parity;019 restore/load/security drills;020 staging/UAT/release request. Each issue must link requirement IDs and the corresponding feature/test row before coding.

## 15. Milestone roadmap and rollback

Estimates are planning ranges in engineering effort, contingent on staffing/unknowns; not delivery promises. Whole pilot roughly28–48 person-weeks plus external waiting and independent review; parallel staffing changes elapsed time, not guaranteed effort. Scope prioritizes correct foundations and owner-confirmed workflows.

| Milestone | Effort | Acceptance gate | Rollback / dependencies |
|---|---|---|---|
| M0 architecture + account evidence | 1–2 weeks | Architecture approval; host/legal unknowns tracked | Documents only; no production mutations |
| M1 repo + tenant/auth/CI | 3–5 | A/B tenant adversarial suite, role caps, provisioning, session revocation | Revert scaffold/release; synthetic data only |
| M2 onboarding/demo/subscription/file foundations | 4–6 | Free live, expiry, promotion750MB, manual billing replay, private quota race | Disable new tenant activation; preserve ledger; contract-compatible rollback |
| M3 academics/accounts/guardian history | 3–5 | Admission/profile distinction, current/future sessions, promotion and history | Read-only relevant commands; corrective enrollment records |
| M4 daily school workflows | 4–6 | Attendance cutoff/events, notices approval, homework no-upload, timetable/leave | Feature pause, no event deletion; keep formal history |
| M5 exams + school fee ledger + reports | 4–7 | Immutable published results, missing mark exception, ledger reconciliation/exports | Stop publication/collection; append corrections, preserve receipt numbers |
| M6 Flutter parity + UX/UAT | 5–9 | Six-role essential journeys, low-end device/network, accessibility | Compatible API retained; previous signed app remains supported |
| M7 hardening/restore/staging pilot | 4–8 | Load envelope, restore drill, privacy setup, no high defects | Separate release authorization; emergency read-only/release rollback |

Imports, comprehensive certificates, payroll, chat and paid gateway not included in estimates. Dependencies: GitHub approval; verified compatible Hostinger plan; safe staging; authorized Edu origins; sender setup only if email used; legal/retention and receipt issuer data; mobile signing/Play publishing authorization. If no safe hosted staging capacity, use isolated local/CI tests until separately approved hosting option; do not consume production slots covertly.

## 16. Cross-discipline review and resolved contradictions

| Finding | Resolution / residual risk |
|---|---|
| General principal authority vs account creation | Split student profile/enrollment from auth provisioning; immutable SA-only capability. |
| Pending school demo vs live-only provisioning | Restricted verified applicant membership may trial private data; school-user live activation never implicit. |
| Approval/payment/access conflation | Orthogonal state dimensions; explicit entitlement grants including free activation. |
| Expiry versus renewal proof uploads | Narrow renewal command allowlist + bounded platform physical reserve, no academic write bypass. |
| 1GB demo →500MB live | Atomic stage transition preserves existing objects and read access; new uploads blocked only. |
| 50 demos vs50GB storage | Aggregate reserved quota admission control; cannot promise all demos plus overhead on same disk. |
| 3GB DB vs retained attendance/audit | Arithmetic sizing and measured index growth; no unbounded historical-capacity promise. |
| 16:00 rule boundary/late first attendance | Exactly16:00 locked; reasoned SA correction; first late submit audited, not a correction bypass. |
| Teacher notice edited after review | Approval bound to exact revision; edits resubmit. |
| Leave/substitute events vs generic notification service | Family recipients explicitly prohibited for these event types, tested at outbox construction. |
| Receipt issued but PDF render fails | Persist immutable receipt identity; retry rendering same version/number. |
| Static Next fallback vs dynamic IDs | Fixed authenticated shell pages/client fetch, no SSR assumptions; spike required before deployment claim. |
| Sanctum subdomains vs Nursery cookie isolation | Host-only API auth cookie, exact allowed origin and CSRF bootstrap integration test; no parent-domain auth cookie. |
| Current docs vs old runtime guide | Composer ^8.4.1 wins over guide8.3; final runtime evidence required. |
| Private files same hosting account | Separate app paths/credentials reduce errors but not account compromise blast radius; segregated account/VPS future risk decision. |
| Backup staging too small for full media | Coordinated snapshot or stream/chunk off-host; off-host destination not yet verified. |
| Student/parent historical visibility vs guardian revocation | Read policy checks current legal link restrictions and retained session scope; revocation invalidates access and downloads. |
| Comprehensive ERP vs small pilot | Owner-confirmed workflows staged across milestones; optional baseline imports/certificates deferred explicitly. |

Residual blockers for **production**, not for architecture approval: Hostinger account compatibility/headroom and staging; approved origins/cookie tests; legal purposes/consent/retention/issuer policy; off-host backup/key recovery; safe upload scanning path; measured load envelope; mobile signing and distribution. Independent penetration/legal review is not performed here. No evidence of actual application correctness exists yet.

## 17. Architecture decision register

| ADR | Category | Decision |
|---|---|---|
| 001 | OWNER-CONFIRMED | Laravel/PHP, Next.js, Flutter/Dart, MySQL-compatible; isolated new Edu repo and assets. |
| 002 | ARCHITECT-SELECTED | Modular monolith + shared Edu DB with school scoping/composite references; conditional topology A. |
| 003 | ARCHITECT-SELECTED | Strict TS based on actual reference files; final patch/runtime locks after compatibility/security checks. |
| 004 | OWNER-CONFIRMED | Six roles; SA-only live accounts; P admission profiles; teacher review requirements. |
| 005 | OWNER-CONFIRMED | 30-day demo,1GB demo/500MB live; staged self-service data promotion with no automatic deletion. |
| 006 | ARCHITECT-SELECTED | Decimal quota units; aggregate reservations and capacity admission controls. |
| 007 | OWNER-CONFIRMED | Manual platform payments, separate school fees; verified money before receipt; free activation possible. |
| 008 | ARCHITECT-SELECTED | Append-only payment events/allocations; versioned numbered PDFs, no statutory tax invoice claim. |
| 009 | OWNER-CONFIRMED | Asia/Kolkata default,16:00 cutoff, SA after-hours correction; in-app absence/correction events. |
| 010 | OWNER-CONFIRMED | No student homework uploads; no chat; no family leave/substitute notifications; dues never block results/admit cards. |
| 011 | ARCHITECT-SELECTED | Sanctum browser session/mobile opaque tokens; host-only cookie and CSRF spike; no Nursery JWT copy. |
| 012 | ARCHITECT-SELECTED | DB outbox/queue, short cron jobs, text-first launch if scanning unavailable, optional push/email adapters. |
| 013 | ARCHITECT-SELECTED | RPO24h/RTO8h targets and proposed alert/load thresholds; require measured acceptance. |
| 014 | HIGH-IMPACT APPROVAL | Repo creation after architecture approval; verify owner,name,private visibility and protection capabilities. |
| 015 | HIGH-IMPACT APPROVAL | Production/DNS/database migration/Play publication/paid services/outbound real messages each separately authorized. |
| 016 | HIGH-IMPACT APPROVAL | Real minors' data privacy basis, retention/deletion, support break-glass, off-host processing/provider and tax treatment before rollout. |

## 18. মালিকের জন্য বাংলা সিদ্ধান্ত ও পরবর্তী ধাপ

NUVIKOZ Edu-এর জন্য একটি Laravel API, একটি unified Next.js TypeScript web app এবং একটি Flutter Android app সুপারিশ করছি। স্কুলভিত্তিক data আলাদা থাকবে; Nursery-এর live API, database বা hosting configuration বদলানো হবে না। Principal admission profile করতে পারবেন, কিন্তু login account তৈরি/activate করবেন School Admin। শিক্ষক notice প্রকাশের আগে Principal অথবা School Admin-এর অনুমোদন লাগবে। বিকেল৪টার পর attendance correction-এর approver কেবল School Admin।

Demo৩০দিন/১GB এবং live default৫০০MB থাকবে। Demo থেকে live হওয়ার সময় পুরোনো file মুছবে না। School approval, access activation, subscription entitlement এবং payment verification আলাদা থাকবে; বিনামূল্যেও live access দেওয়া যাবে। স্কুলের student fee এবং NUVIKOZ subscription হিসাব আলাদা। Homework-এ student file upload বন্ধ থাকবে।

Hostinger-এর বর্তমান public documentation পাঁচটি Node.js website অনুমতি দেখায়; আপনার account-এর খালি slot ও বাস্তব resource এখনও যাচাই হয়নি। এই architecture প্রস্তুত; কোনো Edu software build/test বা deployment সম্পন্ন হয়েছে বলে দাবি করা হচ্ছে না।

**অনুমোদনের প্রস্তাব:** এই conditional topology ও architecture baseline অনুমোদন করে নতুন private `NUVIKOZ_Edu` repository তৈরির অনুমতি দিন। সম্ভাব্য owner `mollanuramin130`; তৈরির আগে account/name/visibility যাচাই করা হবে। এই অনুমতি production deployment, paid purchase, Nursery পরিবর্তন বা real-data privacy policy অনুমোদন নয়।

এই বিরতির কারণ আপনার master prompt-এর Gate2: “NO repository creation or coding yet.” Final stop: “STOP and request the owner's EXPLICIT permission to create the NEW GitHub repository.” সম্পূর্ণ review package দেওয়ার পরই সেই অনুমতি চাওয়া হচ্ছে।

## Sources (official documentation, checked 28 October 2026)

- H1: https://www.hostinger.com/support/hostinger-node-js-limits-for-web-and-cloud-plans/ — process/Node hosting restrictions; account evidence still needed.
- H2: https://www.hostinger.com/support/6976044-parameters-and-limits-of-hosting-plans-in-hostinger/ — current plan listing, not a substitute for legacy hPanel entitlements.
- A1: https://laravel.com/docs/13.x/sanctum — cookie SPA and token capabilities; implementation-specific configuration must be tested.
- Q1: https://laravel.com/docs/12.x/queues — bounded worker concepts, verify switches on selected13.x framework.
- N1: https://nextjs.org/docs/app/guides/static-exports — static export support/limitations.
- F1: https://firebase.google.com/docs/cloud-messaging — optional messaging service, not this account's enabled capability or unlimited delivery promise.
- L1: https://egazette.gov.in/WriteReadData/2025/267647.pdf — statutory commencement notification, use current legal verification.
- L2: https://www.pib.gov.in/PressReleasePage.aspx?PRID=2190655&lang=2&reg=48 — government's explanatory account of DPDP Rules and phased transition; Gazette governs dates.
- Runtime compatibility reference: https://laravel.com/docs/13.x/deployment and https://nextjs.org/docs/app/getting-started/installation .
