# F019-A Staff Profile Foundation

The School Admin directory links existing same-school memberships to staff profiles. An active Principal or Teacher membership is eligible when its identity is active, has completed activation and mandatory password change, and has no Platform identity. Names and roles are read from the linked records. Equal display names are allowed.

All operations use the existing school browser session, current MFA, trusted browser Origin policy and server-derived tenant scope. Registration additionally requires CSRF and enabled, unexpired Live entitlement. Demo and expired entitlements permit authorized reads. Suspended and archived schools deny access. Platform, delegated users and mobile bearer-only callers cannot use these routes.

| Method and path (under `/api/v1/browser/staff`) | Request | Success data |
| --- | --- | --- |
| GET base path | Optional `q` and `cursor` | `{items: Profile[], next_cursor: string|null}` |
| GET `/eligible-memberships` | Optional `q` and `cursor` | `{items: Candidate[], next_cursor: string|null}` |
| GET `/{id}` | Profile ULID | `Profile` |
| POST base path | JSON `{membership_id}` and `Idempotency-Key` | `{resource: Profile, replayed: boolean}`, HTTP 201 or 200 on replay |

Listings use ULIDs in ascending order and return up to 25 entries. Search is case-insensitive literal display-name prefix, trimmed to 2–80 characters when present. Candidates omit memberships that already have profiles. Candidate fields are `membership_id`, `display_name`, and `role`. Profile fields add `id`, `eligible_for_registration`, `created_at`, and `updated_at` (UTC). Historical profiles project current identity and eligibility without exposing login identifiers or contact or credential data.

Responses use the common data or error envelope with request ID and private no-store caching. Requests are limited per school and actor to 60 reads or 20 registrations per minute, with HTTP 429 on exhaustion. Shared session controls supply authentication, Origin, CSRF and MFA errors. Unknown or other-school profile and membership IDs produce 404, invalid inputs 422, non-JSON mutations 415, and non-writable entitlement 403. Conflict responses are 409 with `STAFF_MEMBERSHIP_INELIGIBLE`, `STAFF_PROFILE_EXISTS`, or `IDEMPOTENCY_CONFLICT`.

The idempotency key accepts 16–160 ASCII alphanumeric characters, underscores or hyphens. Registration checks current actor, entitlement and target eligibility before returning an exact replay. The school lock serializes registration and the database uniqueness constraint protects the link. Profile, receipt and `staff.profile.created` audit commit together. Audit details contain the membership ID and trusted role. Reads use existing request/security logging. The migration permits rollback only when profile data, related receipts and audit history are empty and no downstream FK exists.

The `/staff` Web page uses the same browser session lifecycle and clears protected state when the workspace remounts after a security-context change. The API omits entitlement metadata, so the page displays its read-only state after the server denies registration under the entitlement policy. [OpenAPI](../../packages/contracts/f019a-staff.openapi.json)
