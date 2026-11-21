# Module dependency matrix

Phase ownership groups the 43 features once. Cross-mapped requirement IDs intentionally recur. Apply the effective owner corrections before using partially superseded source paragraphs. The [register](../requirements/IMPLEMENTATION_PROGRESS.csv) preserves feature-level links.

## Phase 00 — Baseline and repository readiness

Dependencies: None.

Requirements: A-001, A-002, AE-001, AE-002, AE-003, AE-004, AF-001, AF-002, AF-003, B-001, B-002, B-003, C-001, C-002, COR-R01, COR-R02, X-001, X-002, Y-001.

- **F001 Architecture gates.** Backend/API scope: N/A design gate. Database: N/A design document. Web/Android: Blueprint review on the contracted platforms. Authorization: Document work only until Gate2 approval; no runtime action. Acceptance: T001 — All18 sections and every source paragraph mapped.
- **F002 Repository boundary.** Backend/API scope: N/A GitHub action deferred. Database: N/A git metadata. Web/Android: Repo review on the contracted platforms. Authorization: New repo only after explicit approval; Nursery read-only. Acceptance: T002 — No Nursery mutations; no repo before approval.
- **F033 Database/history.** Backend/API scope: all school endpoints. Database: all school tables. Web/Android: History/session selectors on the contracted platforms. Authorization: Composite tenant FK and scoped queries; no cascade history. Acceptance: T033 — MariaDB integration; restore and migration test.
- **F034 API consistency.** Backend/API scope: /api/v1 contract catalogue. Database: idempotency_records,auth_sessions. Web/Android: Field errors/stale conflict/retry on the contracted platforms. Authorization: Declared capability + scope; no wildcard credential CORS. Acceptance: T034 — Contract/CSRF/replay/batch tenant tests.
- **F039 Portable handoff.** Backend/API scope: N/A future PR workflow. Database: N/A docs/git. Web/Android: CURRENT_STATUS on the contracted platforms. Authorization: Read source of truth before bounded work. Acceptance: T039 — Handoff completeness audit.

Phase exit: Validate 43-row traceability, baseline ancestry, toolchain and isolated testing; publish a reviewed baseline.

## Phase 01 — Platform Super Admin, registration, approval, Demo and activation

Dependencies: 00; reconcile PR #6–13.

Requirements: COR-D01, COR-D02, COR-D03, COR-D04, COR-D05, E-001, E-002, E-003, E-004, E-005, F-001, F-002, F-003, G-001, G-002, G-003, I-001, I-002, V-001, V-002, Y-001.

- **F004 Identity/accounts.** Backend/API scope: POST /accounts; POST /auth/activate. Database: users,memberships,activation_tokens,auth_sessions. Web/Android: School accounts; activate/login on the contracted platforms. Authorization: PSA initial SA; SA school accounts; P denied. Acceptance: T004 — P cannot provision; no email student login; last SA protected.
- **F005 Tenant context.** Backend/API scope: POST /auth/context; GET /me. Database: memberships,membership_permissions,support_sessions. Web/Android: Choose school/security on the contracted platforms. Authorization: Current active membership for every request; support grants exceptional. Acceptance: T005 — School A/B CRUD/export/download/job denial.
- **F007 School registration.** Backend/API scope: POST /registrations; POST /platform/registrations/{id}/review. Database: registration_requests,registration_reviews,schools. Web/Android: Register/tracking/platform queue on the contracted platforms. Authorization: Verified applicant provisional; PSA review. Acceptance: T007 — Private pending demo without live approval.
- **F008 Private demo.** Backend/API scope: GET /demo; POST /platform/schools/{id}/demo; GET /samples; GET /samples/{id}/preview. Database: demo_grants,schools,demo_promotions,demo_resource_usage,sample_assets. Web/Android: Demo dashboard/countdown on the contracted platforms. Authorization: Restricted tenant-scoped provisional admin; server hard-deny every user-supplied file path and tenant-generated file in Demo. Acceptance: T008 — School A demo cannot see B; global disabled features stay off.
- **F009 Demo promotion.** Backend/API scope: POST /demo/promotions/preview; POST /demo/promotions/finalize. Database: demo_promotions,students,enrollments,storage_quotas. Web/Android: Readiness preview with record/sample exclusions and resolved Live policy on the contracted platforms. Authorization: Approved+live_enabled+valid live grant; own records only. Acceptance: T009 — Valid records/IDs/history preserved; shared sample PDFs never transferred; retry idempotent;0Demo user files.
- **F010 Entitlement/expiry.** Backend/API scope: POST /platform/schools/{id}/entitlements; GET /subscription. Database: entitlements,platform_terms. Web/Android: Subscription/expired banner on the contracted platforms. Authorization: PSA free/trial/paid grants; school ordinary writes gated. Acceptance: T010 — Free live no payment; hidden methods and jobs denied at expiry.
- **F040 Demo shared samples.** Backend/API scope: GET /samples; GET /samples/{id}/preview. Database: sample_assets. Web/Android: Demo sample library on the contracted platforms. Authorization: Only approved sample manifest; read-only; no tenant/private ownership. Acceptance: T040 — Seven SAMPLE/DEMO documents preview;one physical copy/version;conversion excludes.
- **F041 Demo upload and resource guard.** Backend/API scope: POST /storage/reservations; POST /imports/files; POST /ingestion/remote. Database: demo_resource_usage,demo_grants. Web/Android: Demo upload explanation and on-screen reports on the contracted platforms. Authorization: Zero user-upload bytes/files; no privileged exception. Acceptance: T041 — Multipart/PDF/image/import/base64/remote/chunk/background denied;student/attendance allowed.

Phase exit: Authorize MFA-gated review, verify proof expiry/replay, isolate tenants, audit transitions and verify explicit activation/entitlement.

## Phase 02 — Academic completion, staff and permissions

Dependencies: 01 tenant scope; reuse PR #9.

Requirements: E-001, E-002, E-003, E-004, E-005, K-001, K-002, L-001, L-002, L-003, L-004, S-001, W-001, W-002, W-003, W-004, W-005, W-006.

- **F006 Delegated permissions.** Backend/API scope: POST /permissions/changes. Database: membership_permissions,permission_templates. Web/Android: School settings/permissions on the contracted platforms. Authorization: Grant only capped capabilities to P/T; no self escalation. Acceptance: T006 — Cannot delegate forbidden capabilities.
- **F015 Session calendar.** Backend/API scope: POST /sessions; POST /sessions/{id}/change-requests. Database: academic_sessions,session_change_requests,global_session_rules. Web/Android: Session calendar/impact preview on the contracted platforms. Authorization: Future SA; current boundaries PSA approval. Acceptance: T015 — Future change independent; active no unauthorized edit.
- **F018 Class/subjects.** Backend/API scope: POST /classes; POST /subject-offerings. Database: classes,class_offerings,sections,subjects,subject_offerings. Web/Android: Classes/subjects on the contracted platforms. Authorization: School settings within caps; classes configurable. Acceptance: T018 — Optional class teacher; grades beyond8 config.
- **F019 Permanent staff.** Backend/API scope: POST /staff; POST /teacher-assignments. Database: staff_profiles,teacher_assignments,memberships. Web/Android: Staff/assignments on the contracted platforms. Authorization: SA alone staff lifecycle and permanent assignment. Acceptance: T019 — Teacher change future only; no deletion of history.

Phase exit: Verify School Admin and delegated authority, effective-dated assignments, future-session dependencies and cross-school denial.

## Phase 03 — Admissions, guardians and promotions

Dependencies: 02 catalog/staff.

Requirements: J-001, K-001, K-002, L-001, L-002, L-003, L-004.

- **F014 Guardian links.** Backend/API scope: POST /guardian-links; POST /contact-change-requests. Database: guardians,guardian_student_links,contact_change_requests. Web/Android: Guardians/child switch/contact requests on the contracted platforms. Authorization: SA verifies relationship; G cannot self-link. Acceptance: T014 — Multiple guardians/children; no child self-link.
- **F016 Student promotion.** Backend/API scope: POST /promotions/preview; POST /promotions/confirm. Database: promotion_batches,enrollments. Web/Android: Promotion preview/results on the contracted platforms. Authorization: School authority with preview and confirmation. Acceptance: T016 — Individual/bulk retry idempotency; undo with dependencies guarded.
- **F017 Admission/profile ID.** Backend/API scope: POST /students; POST /students/{id}/identifiers. Database: students,student_identifier_history,enrollments. Web/Android: Admissions/student profile on the contracted platforms. Authorization: P profile only; SA identifier change/accounts. Acceptance: T017 — Cross-school same number allowed; login rotate tested.

Phase exit: Verify SA-only account provisioning, Principal admission profile limits, guardian link purpose and audited promotion history.

## Phase 04 — Attendance, notices, homework and roles

Dependencies: 03 profiles and 02 assignments.

Requirements: AA-001, COR-S01, M-001, M-002, N-001, N-002, O-001.

- **F020 Attendance/cutoff.** Backend/API scope: POST /attendance/submissions; POST /attendance/corrections/{id}/review. Database: attendance_submissions,attendance_records,attendance_events,attendance_corrections. Web/Android: Attendance/SA approval queue on the contracted platforms. Authorization: Assigned teacher until16; after SA correction only. Acceptance: T020 — 15:59:59/16:00; timezone forge; P approval denied.
- **F021 Absence events.** Backend/API scope: GET /notifications; POST /notifications/{id}/read. Database: notification_events,notification_recipients,outbox,notification_deliveries. Web/Android: Parent notifications/history on the contracted platforms. Authorization: Only linked guardian recipients; no PII push. Acceptance: T021 — No duplicate same event; absent-present correction.
- **F022 Formal notices.** Backend/API scope: POST /notices; POST /notices/{id}/review; POST /notices/{id}/publish. Database: notices,notice_revisions,notice_approvals,notice_publications. Web/Android: Notices/approval queue on the contracted platforms. Authorization: Teacher scoped draft and P OR SA approval; own SA/P direct. Acceptance: T022 — One approver enough; no unapproved leak.
- **F023 Homework done.** Backend/API scope: POST /homework; POST /homework/{id}/completion. Database: homework,homework_completions. Web/Android: Homework and completion list on the contracted platforms. Authorization: Assigned teacher; own student declaration; linked guardian read. Acceptance: T023 — No email or file requirement; parent read scope.

Phase exit: Verify school-local cutoff, approval reason, notice moderation and homework completion with upload denied.

## Phase 05 — Examinations, grading, results and publication

Dependencies: 03 enrollment and 02 catalog.

Requirements: P-001, P-002.

- **F024 Exam/grading.** Backend/API scope: POST /marks; POST /results/{id}/review; POST /exams/{id}/publish. Database: exams,exam_subjects,marks_versions,result_versions,result_approvals,grade_policy_versions. Web/Android: Marks/review/report card on the contracted platforms. Authorization: T assigned entry; P/SA review/publish; SA exception only. Acceptance: T024 — Future grade policy can't change past; no draft leak.

Phase exit: Verify grade overrides, publication authority, audit revisions and tenant-scoped result visibility.

## Phase 06 — School fees, platform subscriptions, renewals and receipts

Dependencies: 01 platform lifecycle; 03 enrollment; receipts coordinate with 08.

Requirements: G-001, G-002, G-003, H-001, H-002, Q-001, Q-002.

- **F011 Renewal reminders.** Backend/API scope: GET /subscription/reminders. Database: renewal_reminders,entitlements. Web/Android: SA renewal/P generic banner on the contracted platforms. Authorization: SA full details; P generic; other roles no commercial content. Acceptance: T011 — No duplicate after renewal; family/teacher no pricing.
- **F012 Manual platform money.** Backend/API scope: POST /subscription/payments; POST /platform/payments/{id}/review. Database: platform_payments,platform_terms,receiving_accounts. Web/Android: Proof/verification queue on the contracted platforms. Authorization: SA submit own; PSA verify actual settlement. Acceptance: T012 — Replay/concurrent approval one receipt/grant.
- **F013 Platform receipt.** Backend/API scope: GET /subscription/receipts/{id}/download. Database: platform_receipts,platform_receipt_counters,platform_payment_adjustments. Web/Android: Receipt preview/download on the contracted platforms. Authorization: Verified paid event only; SA own receipt. Acceptance: T013 — No tenant download leak; immutable receipt snapshot.
- **F025 School fee ledger.** Backend/API scope: POST /fees/collections; POST /fees/corrections; GET /fees/balances. Database: fee_policy_versions,fee_obligations,fee_events,fee_allocations,school_receipts. Web/Android: School fees/due/receipt on the contracted platforms. Authorization: SA policies; P limited audited collection/correction; G linked read. Acceptance: T025 — Dues never block results/admit card; SaaS separation.

Phase exit: Verify separate ledgers, manual evidence, immutable receipts and no implicit activation or renewal.

## Phase 07 — Timetable, calendar, leave and substitution

Dependencies: 02 staff and catalog.

Requirements: R-001, R-002, S-001.

- **F026 Timetable/calendar.** Backend/API scope: POST /timetables/generate; POST /timetables/{id}/publish. Database: school_calendar,rooms,timetable_versions,timetable_slots. Web/Android: Timetable draft/conflict editor on the contracted platforms. Authorization: SA/P generate draft and publish; readers scoped. Acceptance: T026 — Teacher/section/room clash; half-day/calendar.
- **F027 Leave/substitute.** Backend/API scope: POST /leave; POST /leave/{id}/review; POST /substitutions. Database: teacher_leaves,substitutions,timetable_slots. Web/Android: Leave/internal calendar on the contracted platforms. Authorization: T own request; P/SA review/temp substitute; SA permanent. Acceptance: T027 — No parent/student outbox; substitute clash rejected.

Phase exit: Verify calendar scope, overlap detection, leave approval and substitution conflicts.

## Phase 08 — Reports, documents, private storage and quotas

Dependencies: 01 lifecycle and applicable domain data; F041/F042 before file workflows.

Requirements: COR-F01, COR-F02, COR-F03, COR-F04, COR-F05, COR-G01, COR-P01, COR-S02, T-001, U-001, V-001, V-002.

- **F028 Reports/export.** Backend/API scope: POST /reports; GET /reports/{id}/download. Database: report_jobs,documents,storage_objects. Web/Android: Reports/columns/history on the contracted platforms. Authorization: School/assigned/own/linked; P finance explicit grant. Acceptance: T028 — Hidden PII and tenant rows excluded; revoked link denies.
- **F029 Certificates (later).** Backend/API scope: POST /documents/requests; POST /documents/{id}/issue. Database: document_templates,document_issuances,document_versions. Web/Android: Document request/issuance register on the contracted platforms. Authorization: Authorized signatory only; released child scope. Acceptance: T029 — Not pilot-critical; dues no admit-card lock.
- **F030 Private uploads/quota.** Backend/API scope: POST /storage/reservations; POST /storage/reservations/{id}/content. Database: storage_objects,storage_quotas,upload_reservations,storage_usage_events,storage_policy_versions,pdf_daily_usage. Web/Android: Attachment/quota meter on the contracted platforms. Authorization: Demo absolute deny; Live resource/feature/entitlement and size+count+day+byte policy; PSA allocation only. Acceptance: T030 — Free5MB boundary,50active,10/day,500MB,80%warn;concurrent replacement/version/policy/midnight tests;read remains.
- **F031 Quota administration.** Backend/API scope: POST /platform/schools/{id}/quota; POST /platform/storage-policies; POST /platform/schools/{id}/storage-policy. Database: storage_quotas,global_storage_budget,platform_audit_events,storage_policy_versions. Web/Android: Platform quota allocation on the contracted platforms. Authorization: PSA only; reason and before/after. Acceptance: T031 — Overquota CRUD allowed active entitlement.
- **F042 PDF admission accounting.** Backend/API scope: GET /storage/policy; POST /platform/schools/{id}/storage-policy. Database: storage_policy_versions,storage_quotas,pdf_daily_usage,upload_reservations,storage_objects. Web/Android: Free/Paid quota panel and limit-specific errors on the contracted platforms. Authorization: All resolved limits conjunctive;student upload fixed disabled. Acceptance: T042 — 5MB,50/51,10/11,500MB,version/count/day race;Paid override.
- **F043 Generated document provenance.** Backend/API scope: POST /reports; GET /documents/{id}/download. Database: storage_objects,report_jobs,platform_receipts. Web/Android: Reports/receipt render status on the contracted platforms. Authorization: Backend chooses source/payer;no user-PDF count;Demo physical generation denied. Acceptance: T043 — Generated PDFs count physical bytes but no upload slots/day;no loops.

Phase exit: Verify private authorized downloads, Demo zero-file rules across HTTP/jobs, atomic quota and document provenance.

## Phase 09 — Role dashboards, mobile parity and delivery

Dependencies: 02–08; cross-phase role parity; no real delivery.

Requirements: AA-001, W-001, W-002, W-003, W-004, W-005, W-006, Z-001, Z-002.

- **F032 Role dashboards/mobile.** Backend/API scope: GET /dashboard; GET /client-policy. Database: client_policies,memberships. Web/Android: All role routes in section10 on the contracted platforms. Authorization: Capabilities filtered but API rechecks. Acceptance: T032 — Six role journeys low-end device/accessibility.
- **F035 Queue delivery.** Backend/API scope: GET /platform/operations. Database: outbox,job_leases,failed_jobs,notification_deliveries. Web/Android: Platform job failures/replay on the contracted platforms. Authorization: Bounded leases; recheck policy; no unapproved messages. Acceptance: T035 — No dup after crash; expired scheduled writes denied.

Phase exit: Verify scoped dashboards, native vault, authorized device workflows and synthetic deduplicated outbox.

## Phase 10 — Hardening, backup, QA and final release

Dependencies: 01–09 plus independently approved release.

Requirements: AB-001, AB-002, AC-001, AD-001, AD-002, D-001, D-002, D-003, D-004, D-005, D-006.

- **F003 Hosting topology.** Backend/API scope: GET /client-policy. Database: operational_observations,client_policies. Web/Android: Platform resources on the contracted platforms. Authorization: Read account evidence; deployment separate approval. Acceptance: T003 — Run A/B/C compatibility before claims.
- **F036 Privacy/security.** Backend/API scope: POST /privacy/requests; POST /support-access. Database: privacy_records,privacy_requests,support_sessions,audit_events. Web/Android: Privacy requests/support history on the contracted platforms. Authorization: Lawful purpose/legal review; no universal support PII. Acceptance: T036 — BOLA/upload/privilege attack cases; no PII logs.
- **F037 Backup/recovery.** Backend/API scope: GET /platform/backup-health. Database: backup_manifests,operational_observations. Web/Android: Backup health/resources on the contracted platforms. Authorization: Read evidence; production restore separate approval. Acceptance: T037 — Restore rows/files/ledger/outbox to RPO/RTO.
- **F038 Quality acceptance.** Backend/API scope: N/A planned test commands. Database: N/A test fixtures. Web/Android: UAT report on the contracted platforms. Authorization: Synthetic only; no production load/security scans. Acceptance: T038 — Every owner rule and tenant path must pass.

Phase exit: Verify recovery drills, audit and privacy checks, capacity, regression and independently approved final release.
