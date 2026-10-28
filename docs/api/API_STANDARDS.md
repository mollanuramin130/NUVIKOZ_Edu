# API contract standards

Canonical OpenAPI:../../packages/contracts/openapi.json. Relative base/api/v1;design only. Every operation requires declared capability plus tenant/resource policy;platform powers confer no automatic child PII access.

Use allowlisted JSON fields,UTC timestamps/server school-local dates,integer paise/bytes,bounded pagination,deterministic error envelope and request IDs. Cross-tenant hidden resources404;unauthenticated401;forbidden/expired/disabled403;state/version/idempotency409;field validation422;oversize413;throttle429. No credentialed wildcard CORS.

Critical command Idempotency-Key binds tenant,actor,operation,key and canonical body hash. Domain uniqueness remains after key expiry. Browser session/CSRF and Flutter device-token contracts must be integration-tested before claiming auth complete. No tokens in localStorage/logs/URLs. Read projections and complex validators must be refined with compatible contract review before coding each milestone.
