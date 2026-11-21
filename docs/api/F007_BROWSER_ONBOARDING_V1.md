# F007 browser registration and review · 1.0.0

Implementation subset of F007 and owner requirements F-001–003. The browser additions coexist with the previously verified public intake routes. There is no Android onboarding API change.

## Transport

Routes below are under `/api/v1/browser`. The existing allowlisted HTTPS Origin, host-only cookie, no-store headers, and CSRF middleware apply. Obtain CSRF through `GET /csrf` and send `X-CSRF-TOKEN` with POSTs. Bearer tokens and school context cannot authorize platform review. Platform review additionally requires an active Platform Super Admin and current session-bound platform MFA. The existing synthetic platform issuer only works in the testing environment with its explicit test flag. Web pages are `/register` and `/platform/registrations`.

## Public application

| Method and path | Input | Data |
|---|---|---|
| GET /registration/notice | No query | `notice_version:string`, `notice_text:string` |
| POST /registration/submit | JSON `organization_name:string(2–160),category:string(1–40),contact_name:string(1–100),contact:string(1–160),notice_version:string,notice_acknowledged:true` | 201 `id:ULID,state:submitted,support_contact:string,demo_available:false,tracking_token:string` |
| POST /registration/status | JSON `tracking_token:string` | `id:ULID,state,support_contact:string,demo_available:false` |

Submission and lookup accept at most 4 KiB JSON and reject undeclared fields and query parameters. The server validates email or international phone and exact notice version. The public Web form requires owner-supplied `EDU_REGISTRATION_NOTICE_TEXT` alongside existing opt-in and notice version. A version records acknowledgement but does not establish control of the contact address. The 43-character base64url tracking token is shown once and stored only as a hash on the server. Keep the token out of URLs and logs. Duplicate applications use the existing unique normalized organisation/category/contact digest. Response codes include 404 for unavailable intake or unknown token, 409 for duplicate, 413 for oversized body, 415 for non-JSON, 422 for invalid input and 429 for throttling. Status never exposes contact or review notes.

## Platform review

| Method and path | Input | Data |
|---|---|---|
| GET /platform/registrations | Optional `state:submitted\|reviewing\|approved\|rejected`, `search:string(2–80)` literal organisation substring, `cursor:ULID` | `items:[{id,organization_name,category,state,submitted_at}], next_cursor:ULID|null`; at most 25 results, descending ULID |
| GET /platform/registrations/{registration} | Registration ULID, no query | `registration` with queue fields plus `contact_name,contact,notice_version`; `history:[{id,actor_user_id,from_state,to_state,note,created_at}]` |
| POST /platform/registrations/{registration}/review | Strict JSON `state:reviewing\|approved\|rejected, note:string(1–1000), command_id:UUID`, max 8 KiB, no query | `id:ULID,state,review_id:ULID,replayed:boolean` |

The only transitions are submitted → reviewing → approved/rejected. Terminal decisions are immutable. Repeating the same UUID for the same record, actor and payload returns the original review ID and current application state. Conflicting reuse and invalid transitions return 409. The transaction locks current reviewer identity and application, validates platform MFA again and records old/new status, reviewer, note and request ID in the append-only history. Missing application is 404. Authentication, authorization and CSRF failures are 401, 403 and 419. Invalid payloads use 422, 413 and 415 as appropriate. Requests are rate-limited. Envelopes follow `{data,meta:{request_id}}` or `{error:{code,message,fields},meta:{request_id}}`.

## Boundaries

Approval updates application status and history only. No school, membership, activation proof or entitlement is created. Verified-applicant issuance, provisional 30-day Demo, demo read-only shared sample authorization, conversion and explicit Live entitlement issuance still need implementation. The existing synthetic School Admin bootstrap and browser-only activation paths remain isolated. Their staging proofs are unsuitable as a public provisioning issuer. Existing Demo upload denial and Live quota policy stay in force. No new upload endpoint is exposed.

The local migration `2026_11_22_000011_create_registration_reviews` adds the queue index and the history table with append-only update/delete triggers on SQLite and MySQL/MariaDB. Empty-data rollback is supported. With review rows present, rollback refuses to discard the audit trail and requires a preservation plan.

## Approved-school provisioning

`POST /api/v1/browser/platform/registrations/{registration}/provision` accepts only JSON `{"command_id":"UUID"}` (maximum 1 KiB) and no query parameters. It requires the same allowlisted Origin, browser session, CSRF token and current Platform Super Admin MFA as review. A valid approved application returns HTTP 201; replay by the same actor with the same UUID returns 200 with `replayed: true`. Non-approved applications or attempts to provision the same registration with a different command or actor return 409. The existing detail endpoint includes the nullable `provisioning` field.

The response is `{provisioning:{school_id,code,created_at,actor_user_id,state:"provisioned",activation_state:"awaiting_verified_delivery"},replayed:boolean}` in the existing data envelope. ULID-derived school codes are unique. The application row and platform actor are locked while the school, inactive School Admin identity and membership, unique application link, and tenant audit event are created in one transaction. Failure rolls back that transaction. The link migration refuses rollback while provisioned records remain. Public tracking continues to show application status only.

The school starts suspended and has no Demo deadline or Live entitlement. The initially provisioned administrator cannot sign in. There is no proof issuance endpoint for these accounts while verified applicant identity and trusted private delivery are unavailable. Existing browser-only proof consumption, expiration, staging reissue and staff activation stay unchanged. No production email/SMS infrastructure is configured, and no proof or password is returned. Future issue, reissue and revoke commands must bind the verified recipient, tenant, pending membership and authorized issuer, and audit each transition without recording proof values.

## Provisioned School Admin activation

`POST /api/v1/browser/platform/registrations/{registration}/activation/{operation}` accepts `issue`, `reissue`, or `revoke`. Send only `{"command_id":"UUID"}` as JSON, up to 1 KiB, with no query. Current Platform Super Admin browser MFA, Origin, CSRF and a suspended provisioned school are required. Same actor and command replays without generating a secret or audit entry. A mismatched replay or invalid transition returns 409; a missing approved and provisioned subject returns 404; an unavailable adapter returns 503 for issuance. The combined mutation throttle allows 10 requests per minute.

The detail response adds `activation: {state,delivery_configured,history}`. A successful command returns `{activation,replayed}`. History shows the most recent 25 actions with `sequence,state,operation,expires_at,created_at,actor_id`. Responses omit proof secrets, recipient references and encrypted payloads. The workflow is awaiting verified delivery → pending delivery → delivered → consumed. Expiry, revocation and replacement disable previous material; replacement records it as superseded. The guarded browser `POST /api/v1/browser/activation/consume` keeps its existing DTO and CSRF/session rotation. It also accepts delivered initial School Admin proofs for the corresponding provisioned school. Consumption activates the identity and membership, and leaves the school suspended with its entitlements unchanged.

For any future rollout, bind an approved `App\Services\VerifiedSchoolAdminDelivery` implementation in the Laravel container. Its verification must independently validate contact ownership and supply a stable, registration-scoped recipient reference. Configure a private operator/worker to run `edu:deliver-school-admin-proof {registration}` on pending rows. It must use a bounded provider timeout, a private channel and provider-side deduplication by proof ID. The adapter may acknowledge only actual verified private delivery. The envelope uses application encryption and is removed on acknowledgement, cancellation or expiry. Set up Laravel's scheduler to execute the minute-based expiry scrub. Protect encryption keys and database backups. Always keep proofs out of URLs, logs, analytics, ordinary API responses and queue job parameters. The implementation ships with no adapter, contact-verification integration or delivery worker configured.

Migration `2026_11_22_000013_create_school_admin_activation_proofs` adds an independent table linked to provisioned registrations, and refuses rollback while it contains activation records. This flow does not change staging bootstrap or staff proofs.
