# Mobile Academic API handoff · 1.0.0

Review branch: `integration/edu-mobile-academic`. Base `7eb24d422f1fb94e8897b99c3ec5e8825a6ffa1c`, Mobile API source `eb751810c451fc0aff8d5cbd3b9cbd2c160b8ad3` and Flutter source `633e3a99c3db84191c1e7e697387e86643671fe9`. The integration uses the baseline native Keystore and CI harness and imports the upstream Flutter workspace, assets implemented in Dart, and tests. Use the exact reviewed integration commit, with disposable fixtures.

## Transport and assurance

Use HTTPS, `Authorization: Bearer <device token>`, `X-School-ID: <current school ULID>`, and `Accept: application/json`. The token must authorize that school and resolve to an active identity and membership. Academic is School Admin only. Reads use school-scoped identity and can remain available after entitlement expiry. Writes require current write entitlement and a valid device proof.

| Method / route | Request | Response under data |
|---|---|---|
| GET /api/v1/security/mfa/status | Headers only | `enrolled:boolean, verified:boolean, assurance_seconds:900` |
| POST /api/v1/security/mfa/challenge | `password:string<=72, code:string<=32, mode?:totp|recovery`; default totp | `verified:true, assurance_seconds:900` |
| GET /api/v1/academic/{kind} | Optional ULID cursor and kind-specific filters | `items:array, next_cursor:ULID|null`, page size up to 25 |
| POST /api/v1/academic/{kind} | JSON body below and `Idempotency-Key`, 16–160 URL-safe characters | HTTP 200, `resource:object, replayed:boolean` |

MFA requires an enrolled authenticator and current password. Recovery codes are one-use. TOTP counters are checked against replay. An issued proof lasts up to 900 seconds and binds the active stored token, credential version, user, school, membership and identity snapshot. MFA proof issuance and TOTP/recovery consumption are audited. Native proof applies to Academic writes only and grants no browser session, platform or School Admin device inventory access. Browser Academic retains the original cookie, Origin, CSRF and browser-MFA boundaries. Use Web for enrollment and School Admin device inventory.

## Academic data

Each resource returns its body projection plus `id:ULID` and `version:integer`. In sessions the server also returns `state`. Absent/null optional values on classes and sections follow the [existing schemas](../../packages/contracts/m2-academic.openapi.json).

| Kind | JSON create fields | GET filters in addition to cursor |
|---|---|---|
| sessions | `name:string<=60, starts_on:YYYY-MM-DD, ends_on:YYYY-MM-DD` | none |
| classes | `name:string<=80, grade_label?:string<=32|null, sort_order:integer[0..2147483647]` | none |
| class-offerings | `session_id:ULID, class_id:ULID` | session_id, class_id |
| sections | `class_offering_id:ULID, name:string<=40, class_teacher_membership_id?:null` | class_offering_id |
| subjects | `code:string<=32, name:string<=100` | none |
| subject-offerings | `class_offering_id:ULID, subject_id:ULID, compulsory:boolean` | class_offering_id, subject_id |

The backend enforces same-tenant relationships, future session dates, entitlement and Demo caps. Unknown fields are rejected. Writes share the existing Academic transaction and idempotency receipt with Web, including audit-once behavior. Repeat the same idempotency key and payload for a replay. A conflicting payload or duplicate record yields 409. A distinct Flutter user submission creates a new key. A fresh session or token must verify its own MFA.

Success envelope is `{data,meta:{request_id}}`, failure is `{error:{code,message,fields},meta:{request_id}}`. Handle 401 with sign-in, 403 with role/context/entitlement and MFA diagnosis, 404 with unavailable or inaccessible record, 409 with conflict messaging, 422 with validation, and 429 with backoff. Do not assume every 403 is MFA. No write retry should bypass idempotency or tenant checks. Credentials and validation details must not enter app logs.

## Antigravity verification

Review this commit together with the source Android commit, run the repository's isolated Flutter analysis/tests and HTTPS integration CI, then use the authorized OPPO F25 Pro CPH2603 for physical acceptance. Use synthetic data and an explicitly configured isolated origin for development testing. Emulator, hosted deployment, hosted migrations and Phase 01 coding are outside this integration.
