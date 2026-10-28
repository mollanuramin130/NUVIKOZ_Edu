# Relationships and flows

These are scoped ERD views of the full logical dictionary. All school-to-school links below use composite school_id + id references; diagrams abbreviate columns for readability. Platform entities refer to school beneficiaries explicitly. No public child-data flow exists.

## Identity and guardians

```mermaid
erDiagram
 direction TB
 USERS ||--o{ MEMBERSHIPS : has
 SCHOOLS ||--o{ MEMBERSHIPS : scopes
 STUDENTS ||--o{ GUARDIAN_LINKS : protected_by
 GUARDIANS ||--o{ GUARDIAN_LINKS : verified_for
```

## Session enrollment

```mermaid
erDiagram
 direction TB
 SESSIONS ||--o{ CLASS_OFFERINGS : contains
 CLASS_OFFERINGS ||--o{ SECTIONS : has
 SECTIONS ||--o{ ENROLLMENTS : enrolls
 STUDENTS ||--o{ ENROLLMENTS : retains_history
```

## Attendance audit

```mermaid
erDiagram
 direction TB
 ENROLLMENTS ||--o{ ATTENDANCE_RECORDS : has
 ATTENDANCE_RECORDS ||--|{ ATTENDANCE_EVENTS : records
 ATTENDANCE_RECORDS ||--o{ CORRECTIONS : requests
 ATTENDANCE_EVENTS ||--o{ NOTIFICATION_EVENTS : emits
```

## Academic publication

```mermaid
erDiagram
 direction TB
 EXAMS ||--|{ EXAM_SUBJECTS : specifies
 EXAM_SUBJECTS ||--o{ MARKS_VERSIONS : records
 EXAMS ||--o{ RESULT_VERSIONS : publishes
 GRADE_POLICY_VERSIONS ||--o{ RESULT_VERSIONS : snapshots
```

## School fees

```mermaid
erDiagram
 direction TB
 ENROLLMENTS ||--o{ FEE_OBLIGATIONS : owes
 FEE_EVENTS ||--o{ FEE_ALLOCATIONS : allocates
 FEE_OBLIGATIONS ||--o{ FEE_ALLOCATIONS : settles
 FEE_EVENTS ||--o| SCHOOL_RECEIPTS : documents
```

## Platform billing (separate ledger)

```mermaid
erDiagram
 direction TB
 SCHOOLS ||--o{ PLATFORM_PAYMENTS : submits
 PLATFORM_PAYMENTS ||--o| PLATFORM_RECEIPTS : verifies
 SCHOOLS ||--o{ ENTITLEMENTS : receives
 PLATFORM_PAYMENTS |o--o{ ENTITLEMENTS : may_fund
```

## Private storage

```mermaid
erDiagram
 direction TB
 DOCUMENTS ||--|{ DOCUMENT_VERSIONS : versions
 STORAGE_OBJECTS ||--o{ DOCUMENT_VERSIONS : stores
 SCHOOLS ||--|| STORAGE_QUOTAS : limits
 SCHOOLS ||--o{ UPLOAD_RESERVATIONS : reserves
```

## Module dependency view

```mermaid
flowchart TD
 Context["Identity and tenant context"] --> Gate["Authorization and entitlement"]
 Gate --> Academic["Academic and student records"]
 Gate --> Billing["Separate platform billing"]
 Academic --> Daily["Attendance, homework and notices"]
 Academic --> Finals["Exams, school fees and timetable"]
 Daily --> Events["Transactional events and audit"]
 Finals --> Events
 Billing --> Events
 Events --> Work["Bounded jobs and authorized reports"]
 Work --> Files["Private storage and downloads"]
```

## Data trust boundary

```mermaid
flowchart TD
 Clients["Web and Flutter"] --> API["Authenticated API boundary"]
 API --> Policies["Tenant, resource and state policy"]
 Policies --> DB["Edu database transaction"]
 DB --> Outbox["Scoped event outbox"]
 Outbox --> Jobs["Reauthorized bounded jobs"]
 Policies --> Download["Authorized private download"]
 Jobs --> Storage["Private objects"]
 Storage --> Download
```

## Expired entitlement behavior

```mermaid
stateDiagram-v2
 [*] --> Effective: explicit free or paid grant
 Effective --> Expired: server time reaches end
 Expired --> Effective: authorized renewal grant
 Effective --> Revoked: authorized revocation
 Expired --> HistoricalRead: authorized query
 HistoricalRead --> Expired
 Expired --> Renewal: renewal proof or support
 Renewal --> Expired: proof awaiting verification
```

Source prompt lifecycle rules remain authoritative. Scope diagrams do not imply unrestricted relationships, cascading deletes, compulsory payment or proof-driven activation.

## Shared samples and upload limits (ADR-018)

```mermaid
erDiagram
 direction TB
 STORAGE_POLICY_VERSIONS ||--o{ STORAGE_QUOTAS : governs
 SCHOOLS ||--|| STORAGE_QUOTAS : owns
 SCHOOLS ||--o{ PDF_DAILY_USAGE : counts
 STORAGE_QUOTAS ||--o{ UPLOAD_RESERVATIONS : protects
```

SAMPLE_ASSETS is a platform manifest with no school ownership foreign key. Demo preview resolves the shared entry; conversion cannot turn that reference into a private document. Demo stage denies all tenant-upload/reservation/import/generated-file paths. Non-file records remain tenant-scoped and rate-limited.
