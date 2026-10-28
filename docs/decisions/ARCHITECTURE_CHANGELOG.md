# Architecture change log

## v1.1 — 28 October 2026

| Change | Reason and affected contracts |
|---|---|
| Demo: 0 upload bytes, 0 user files | Owner correction. Covers every role, client, import, base64, remote fetch and queued job; no privileged exception. |
| Interactive non-file Demo retained | Student/academic/attendance/marks/notices/timetable/fee simulations remain available with finite record, DB, request and report limits. |
| Shared sample library | Seven synthetic read-only PDFs marked SAMPLE / DEMO; physically stored once per content version, excluded from private conversion. |
| Free Live limits fixed initially | 5 MB per PDF, 50 active uploaded PDFs, 10 new PDFs per school-local day, 500 MB total. All limits apply together; warn80%, block100%. |
| Paid policy versions | PSA plan/school overrides apply without code changes; reason and before/after audit. No compulsory paid storage provider. |
| Accounting split | Active logical PDF slots, daily blob admissions, retained bytes and system-generated provenance are distinct. Replacement consumes daily admission and physical bytes. |
| Atomic admission | Ordered global/school/policy/day/document locks, reservations, commit-time checks, midnight/timezone handling and reconciliation. |
| Renewal proof exception removed | User files always follow school caps. Text references and support remain available; Demo screenshots always forbidden. |
| Generated PDFs controlled separately | No user upload count/day consumption; every stored byte counted. No Demo tenant file generation, bounded Live jobs/cache/spool. |
| Complete architecture propagation | Updated requirements, feature contracts, schema/ERD, OpenAPI, web/Flutter flows, tests, resource assessment, roadmap, ADR and handoff. |
| Historical separation | Original evidence retained only under historical/ with an explicit superseded-policy warning. Active design uses correction instructions. |
| Repository preparation | Authenticated account and reference-repo admin access checked read-only. Creation capability unavailable; no external repository or branch created. |

Unchanged: technology families, conditional topology A, six roles, SA-only login provisioning, Principal admission profiles, teacher notice review and attendance cutoff, separate school/platform ledgers, expiry read-only, result history, dues not blocking results/admit cards, family notification restrictions and Nursery isolation.
