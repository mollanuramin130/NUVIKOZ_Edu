# Android Agent handoff — WORK-P00-002

Use the published `integration/edu-development-baseline` revision from the WORK-P00-002 report. Verify the exact checked-out SHA before taking a new task. Source evidence is accessible at [main foundation](https://github.com/mollanuramin130/NUVIKOZ_Edu/commit/c9ae3cd5d5cc2dc7683fe61576fbc851f49b85ff), [platform + native validation](https://github.com/mollanuramin130/NUVIKOZ_Edu/commit/5276e20bf54b8e2d2d387cd8ca8edb389b84c663), [offline engine harness](https://github.com/mollanuramin130/NUVIKOZ_Edu/commit/78e25234320f978bcf4bb1b79867ec7f3884c653), [Academic API/UI](https://github.com/mollanuramin130/NUVIKOZ_Edu/commit/491385b8a085049bb4162448f9cb98043d103bd2) and [staff/registration release line](https://github.com/mollanuramin130/NUVIKOZ_Edu/commit/94d155452adf777f08768cc7ff02d9f3ea8787df).

## Implemented device transport

Use HTTPS and the existing no-redirect transport. Success is `{data,meta:{request_id}}`; errors are `{error:{code,message,fields},meta:{request_id}}`. Send JSON and accept JSON on commands. Keep credentials out of logs and URLs.

| Route | Request | Response data |
|---|---|---|
| GET /api/v1/health | Public | `status,api_version` |
| POST /api/v1/auth/login | `school_code,identifier,password,device_name?` | `token,expires_at,school_id,membership_id,user_id,role,abilities[],must_change_password` |
| GET /api/v1/me | Bearer | `id,must_change_password,memberships[{id,school_id,role}]` |
| GET /api/v1/schools/{school} | Bearer, X-School-ID | `id,code,name,data_stage` |
| POST /api/v1/auth/logout | Bearer, no fields | `revoked:boolean` |
| POST /api/v1/auth/password | Bearer, `current_password,password,password_confirmation` | `reauthentication_required:boolean` |
| GET /api/v1/security/devices | Bearer, X-School-ID | `devices[{id,name,current,issued_at,last_used_at,expires_at}]` |
| POST /api/v1/security/devices/{id}/revoke | Bearer, X-School-ID, `password` | `revoked:integer` |

For tenant routes use the selected school ID as `X-School-ID`. A matching `school:<id>` token ability, active account and active membership are checked by the server. Password change, suspended/archived schools, account activation and token revocation retain existing denials. Device inventory has additional service-level authorization. The native privileged provisioning and platform health routes are denied. See [implemented](../../packages/contracts/m1-implemented.openapi.json) and [security](../../packages/contracts/m1-security.openapi.json) contracts.

Android uses `nuvikoz.edu/secure-token-store` with `read`, `write({value})` and `clear`. Keep the native vault and fail-closed behavior. Only OPPO F25 Pro `CPH2603` / `EM9D5L998XB6599H` is authorized. Verify both before any execution or install. Use synthetic data, a separate acceptance harness where applicable, and no emulator.

## Browser-only and public contracts

- Academic `GET|POST /api/v1/browser/academic/{kind}` supports `sessions,classes,class-offerings,sections,subjects,subject-offerings`. It requires a school browser session and current School Admin MFA. Writes need CSRF and `Idempotency-Key`, active entitlement and same-school references. GET returns `{items,next_cursor}`; POST returns `{resource,replayed}`. See [DTOs and pagination](../../packages/contracts/m2-academic.openapi.json) for each kind. No native Academic route was present in verified refs. Android may model approved DTOs, but must keep native networking gated until an explicit native scope and backend contract exist. Do not send a bearer token to cookie endpoints.
- Platform route prefix `/api/v1/browser/platform`: POST `login,logout,mfa/enrollment,mfa/confirm,mfa/challenge,mfa/recovery,mfa/replacement/start,mfa/replacement/confirm` and GET `status,identity`. It is synthetic testing only and separate from any school session. Verified MFA authorizes the implemented platform identity capability; no Phase 01 approval, demo provisioning or payment workflow is available. See [platform contract](../../packages/contracts/m1-platform-replacement.openapi.json).
- Registration `POST /api/v1/registrations` accepts `organization_name,category,contact_name,contact,notice_version`; returns `id,state,support_contact,demo_available,tracking_token` on 201. `GET /api/v1/registrations/status` takes the 43-character base64url `X-Registration-Token` header and returns the same safe status fields except the token. Requires HTTPS and opt-in intake configuration. No approval or activation is implied by submission. Keep tracking proof out of URLs and logs.
- School account activation/reissue remains [browser-only](../../packages/contracts/m1-activation.openapi.json). The staged staff proof mechanism is synthetic. Preserve the School Admin provisioning rule, tenant boundary and MFA.

Stop before Phase 01 or new native Academic endpoints. Report missing capabilities as contract gaps and keep unrelated working trees untouched.
