# M2 implemented academic browser subset

Machine contract: `packages/contracts/m2-academic.openapi.json`. Approved generic `/sessions`, `/classes` and other target routes remain DESIGN_ONLY until individually implemented. Actual routes here: GET/POST `/api/v1/browser/academic/sessions` and `/api/v1/browser/academic/classes`. Browser-only; mobile token API unchanged. No device-token substitution.

Existing exact Origin, credentialed CORS, host-only Secure HttpOnly cookies, CSRF, no-store, current-session/tenant and School Admin MFA middleware remain in place. Services independently reload current identity/membership/school and require current MFA. Principal/Teacher/family/platform identities cannot use this setup subset; future scoped read journeys require their own policies. Writes lock school → user → membership; revalidate eligibility/MFA/entitlement after waiting. Expired entitlement permits authorized reads, not new commands/replay.

Session input follows SessionCreate: name (1–60), starts_on and ends_on ISO dates, end >= start, start strictly after today in configured valid IANA `schools.settings.timezone`. No implicit timezone; unavailable setup denies403. State is server-owned `future`, version1. Missing global session rule infrastructure means nullable rule_version, not a fabricated inherited version. Inclusive future/active date intersections and duplicate same-school names conflict409. No activation/close/edit/delete, active-boundary approval or global default workflow is claimed.

Class input follows ClassCreate: name (1–80), optional nullable grade_label (max32), sort_order0..2147483647. Names trim outer whitespace; no limit to Class8. Same-school duplicate name conflicts409 (database collation governs case equality). No class/session offering, teacher, student or guardian auto-creation.

Writes require Idempotency-Key matching `[A-Za-z0-9_-]{16,160}`. Scope: school + actor membership + operation + key. Canonical sorted validated JSON SHA256 binds body; changed body409. Exact retry returns the same resource and replayed=true, without another audit. Reauthorization precedes every replay. Receipt/resource/audit commit together; audit failure rolls everything back. Keys are not credentials. Strings versus numeric JSON types are distinct idempotency bodies.

GET returns `{data:{items:[],next_cursor:null},meta:{request_id:...}}`, at most25 rows ordered by immutable ULID; query accepts only an optional ULID cursor. Unknown fields422, unauthorized403, missing session401, CSRF419, conflicts409 and rate limit429. POST rate20/minute namespace academic-create. No server-trusted school/role/state/version from request.

Demo limits are bounded engineering defaults (10 sessions/50 classes per school), serialized within the same school lock; expired Demo denies writes. No user files/documents are created. Backend integration acceptance is separate from UI/mobile/owner acceptance.
