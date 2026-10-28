# M1 implemented API subset

All paths below start/api/v1. Canonical target remains packages/contracts/openapi.json;implemented subset is packages/contracts/m1-implemented.openapi.json. Do not claim all145target operations are live.

- GET/health andGET/health/ready:public status/version or503readiness failure;no credentials/details.
- POST/auth/login:{school_code,identifier,password,device_name?};school-assigned login,including students without email. Returns opaque device token,expiry,must_change_password. Staff12h/family7d;generic bad credentials401;request throttle.
- GET/me:active identity and its active memberships only.
- POST/auth/logout:revoke current device token.
- POST/auth/password:{current_password,password,password_confirmation};12–72characters;atomic credential rotation/token revocation/audit.
- GET/platform/health:global platform flag only;school roles403.
- GET/schools/{school}:requiresBearer plusX-School-ID. Scoped lookup and active membership recheck;foreign route/header404;missing context403;suspended403.
- POST/accounts:SA only,valid Demo/live write entitlement;payload{name,login_identifier,role},role onlyteacher/principal. RequiresIdempotency-Key(16–160safecharacters). Returns201{membership_id,one_time_password,must_change_password,replayed}. Samekey/body returns pointer with nullsecret;differentbody409;newkey/duplicateidentifier422. Never store a retrievable plaintext secret. First-password change required before school actions. Student/parent provisioning remains unavailable until verified profile/link services.
- POST/storage/reservations,/imports/files,/ingestion/remote,/ingestion/base64,/homework/{homework}/attachments:authenticatedtenant context,Demo403DEMO_UPLOAD_FORBIDDEN;Live403FEATURE_DISABLED. No file ingestion,generation or URL fetch implemented.

Envelope:data+meta.request_id;errors:error{code,message,fields}+meta.request_id. RequestID propagated or regenerated after validation;responsesno-store. Unknown body fields422. BackendnotUIenforcesguardrails. Scopedpermissions table exists;delegation API and operation-level academic capabilities remain unimplemented. Browsercookie/CSRF,activation/reset/MFA,externalmessages and protected frontend integration are next increments,not assertedworking.

## Security review corrections

School login issues only `school:<school ULID>` capability; tenant middleware requires that exact capability plus current active membership/access. A multi-school user must separately authorize the other school; mismatch returns 403 `TOKEN_SCHOOL_MISMATCH`. Unknown/foreign resource IDs in an authorized tenant remain 404. Suspended/archived schools and global platform users receive generic 401 credential denial from school login. Platform health requires explicit `platform:admin`, platform identity and no forced password change; no public platform token issuance exists yet. Discard previous development wildcard tokens.

Provisioning revalidates the teacher/principal allowlist and unknown fields in its service, independent of HTTP validation. HTTP JSON exception responses preserve Allow/Retry-After headers. No new lifecycle endpoint is claimed; the pending contract is `M1_AUTH_INCREMENT_2_CONTRACT.md`.
