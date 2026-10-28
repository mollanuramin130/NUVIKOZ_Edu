# Module boundaries

Laravel modular monolith: TenantOnboarding,Identity,Academic,Student,Attendance,Homework,Exams,Fees,SubscriptionBilling,Notice,Timetable/Leave,Storage,Reports/Documents,Notification,Audit/Operations.

Identity resolves global user and explicit school membership. Authorization plus tenant/context and effective entitlement gate each domain command. Profiles/enrollment are distinct from login provisioning. Domain services own state transitions;DTO/form requests validate allowlisted fields;policies enforce resource scope. Do not add generic repositories unless complex queries justify them.

Attendance/homework/exams/fees consume academic/session/enrollment relations. School fees and platform subscription money never share ledger tables. Storage is an authorized resource service,not a shortcut around academic ownership. Notifications consume committed scoped outbox events;reports cannot become write bypasses. Background work rechecks tenant,stage,permissions and entitlement at execution.

Every school-to-school relation uses school_id plus immutable ID. No reliance on guessed-ID secrecy or a frontend-only guard. Shared samples are platform-owned immutable assets without private tenant ownership. Existing Nursery code/data/API is not a module dependency.
