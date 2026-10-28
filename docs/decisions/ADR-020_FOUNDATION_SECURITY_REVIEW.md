# ADR-020 — Foundation security review and bounded token authority

Date: 2026-10-28. Scope: PR #1 on `feature/m1-tenant-auth-foundation`, reviewed from remote commit `618e6cf24adf767d5c3a70660f508341f50dbe33`. No production or Nursery changes. This is a review of the first foundation increment, not a completion claim for M1.

## Findings and corrections

| Finding | Assessment | Correction |
|---|---|---|
| School login issued `*` tokens, permitting a multi-school user to switch schools using a token issued at another school | Missing explicit token context boundary; existing membership checks still denied unrelated users | Issued token carries `school:<immutable school ID>`; tenant middleware requires that exact capability as well as current active membership and school access |
| Suspended schools and platform identities could use the school login endpoint | Login issuance and privileged identity separation gap | Generic credential denial, no token issuance; platform health additionally requires explicit `platform:admin` capability and no forced-password-change flag |
| Provisioning service accepted arbitrary role/extra fields when invoked without its FormRequest | Latent privilege escalation on service reuse | Service independently validates teacher/principal role and payload; rechecks actor user ID, current authority, password-change requirement and stage inside the command |
| Eloquent bulk/query-builder writes bypassed audit model events | Append-only promise was only partial | Additive audit UPDATE/DELETE rejection triggers on MariaDB and SQLite; retain model guards |
| JSON exception handling discarded HTTP headers | Lost 405 Allow / 429 retry semantics | Preserve exception headers while keeping generic JSON error messages |
| Mobile API origin accepted non-HTTP local schemes and URL credentials | Client configuration validation gap | Allow HTTP only for explicit local development hosts; require HTTPS elsewhere and reject URL credentials |
| CI lacked dependency advisory checks; Flutter was unverified | Verification gaps | Add Composer/npm audit and explicitly isolated Flutter verification |

No remote exploit against an unrelated school was demonstrated: membership checks already existed. The new token capability is an additional authority boundary, not a replacement for membership/role/resource checks. Existing development wildcard tokens must be discarded and users reauthenticate. Multi-school users remain supported through separate school-authorized login contexts. Browser sessions require their own reviewed context binding in increment 2; they must not be made to pass the device-token middleware using a wildcard shortcut.

## Database and concurrency boundaries

The new migration is `2026_10_28_000001_protect_audit_history.php`; the initial identity migration is unchanged. Rollback drops the two triggers before the earlier schema is rolled back. Normal application database credentials must not have DROP/ALTER/TRIGGER privileges in a future deployment; a migration principal creates triggers. Triggers block row UPDATE/DELETE, including bulk query-builder operations. They do not defeat a database administrator, DROP/TRUNCATE, infrastructure compromise or physical backup modification. Offsite tamper evidence and retention remain production gates.

Provisioning serializes through a school row lock, persists audit plus idempotency in the same transaction, and never retains a recoverable plaintext provisional secret. This review does not claim a multi-process race stress test. Future membership/revocation commands must use the same lock ordering and revalidate authority under lock.

## Accepted limits

No browser auth, activation delivery, password recovery, MFA enrollment/challenge, delegated capability API, last-SA mutation flow or protected dashboard records are shipped. Platform token issuance has no public endpoint; privileged enrollment/login is deferred until MFA. No upload feature is enabled. The Next.js role route is a public empty shell, not an authorization barrier. The mobile client only calls public health. Do not deploy this foundation as a complete school system.

The canonical architecture, target API, database dictionary and storage limits remain authoritative. The implemented subset and next-increment contract document deltas explicitly. Automated secret/advisory checks are limited tools, not a penetration-test certification or proof that no vulnerability exists.
