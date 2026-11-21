# Development master roadmap

Develop modules in isolated environments. Keep Edu hosted code, settings, data and DNS frozen until the owner explicitly approves a final release. Nursery resources are outside this development scope. Use synthetic fixtures and disable external delivery in test environments. The effective storage policy is [STORAGE_POLICY_v1.1.md](../security/STORAGE_POLICY_v1.1.md).

| Phase | Scope | Dependencies |
|---|---|---|
| 00 | Baseline and repository readiness | None |
| 01 | Platform Super Admin, registration, approval, Demo and activation | 00; reconcile PR #6–13 |
| 02 | Academic completion, staff and permissions | 01 tenant scope; reuse PR #9 |
| 03 | Admissions, guardians and promotions | 02 catalog/staff |
| 04 | Attendance, notices, homework and roles | 03 profiles and 02 assignments |
| 05 | Examinations, grading, results and publication | 03 enrollment and 02 catalog |
| 06 | School fees, platform subscriptions, renewals and receipts | 01 platform lifecycle; 03 enrollment; receipts coordinate with 08 |
| 07 | Timetable, calendar, leave and substitution | 02 staff and catalog |
| 08 | Reports, documents, private storage and quotas | 01 lifecycle and applicable domain data; F041/F042 before file workflows |
| 09 | Role dashboards, mobile parity and delivery | 02–08; cross-phase role parity; no real delivery |
| 10 | Hardening, backup, QA and final release | 01–09 plus independently approved release |

Use the [dependency matrix](MODULE_DEPENDENCY_MATRIX.md) for requirement IDs and acceptance, the [register](../requirements/IMPLEMENTATION_PROGRESS.csv) for evidence, and the [definition of done](DEFINITION_OF_DONE.md) for phase exit. Obtain the next phase instruction before beginning a new major phase.
