# ADR-021 — Edu browser session boundary

Implemented in AUTH-01–03 on `feature/m1-browser-auth-account-lifecycle` (Draft PR #2).

Use Sanctum's web guard on dedicated `/api/v1/browser/*` routes with Laravel's web/session pipeline. Device bearer routes keep their existing independent school capability checks. Browser routes reject Authorization headers. They require an exact configured Origin, including reads; mutations additionally require the session-bound CSRF token. Fetch metadata never substitutes for CSRF.

The API issues an encrypted, host-only HttpOnly Secure SameSite=Lax cookie. The trusted Next.js frontend obtains CSRF through credentialed JSON bootstrap rather than reading an API-host cookie. Web and API must be HTTPS and same-site. Unrelated cross-site hosting is unsupported; never expand the cookie domain or relax SameSite to accommodate it. Next.js SSR cannot assume it receives the API cookie, so protected shells resolve authority through authenticated client requests before rendering.

Login rotates session ID and CSRF. Session school context is fixed until another authenticated login. Every protected browser request must use BrowserSession in addition to auth:sanctum, which rechecks current membership, school access, identity and credential hash. Staff idle expiry is 30 minutes, family 8 hours, absolute cap 12 hours. Bootstrap and login have separate throttle namespaces. Unauthenticated API requests return JSON regardless of Accept headers.

This increment exposes only current-user browser data. Platform administrators and forced-password-change identities are denied pending their activation/MFA workflows. Activation, recovery, MFA, device management and academic data access are not supplied by this decision. Existing sessions migration is reused. See canonical handoff for executed test evidence; no deployment or merge is authorized.
