# Shared contracts

openapi.json is the canonical v1.1 design API. storage-policy-baseline.json is the owner-confirmed policy fixture,not an implemented authorization engine. API and client changes require reviewed compatible contract updates. No SDK/runtime API claimed.

Implemented additive subsets: `m1-implemented.openapi.json` (foundation), `m1-activation.openapi.json` (activation), `m1-security.openapi.json` (school security/permissions), and `m1-platform-replacement.openapi.json` (synthetic platform browser authentication and School Admin/platform authenticator replacement). The canonical v1.1 target remains separate; these subsets do not claim generated SDKs, production platform issuance or lost-factor verification.
