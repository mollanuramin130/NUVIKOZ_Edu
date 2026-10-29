# M2 — Academic Setup (incremental, not accepted)

Owner strategy: continuous automated verification; milestone physical-device acceptance. Existing approved v1.1 requirements remain authoritative. This roadmap is implementation sequencing, not a replacement SRS.

## Dependencies and deliverables

Identity + current tenant + entitlement + School Admin MFA → future sessions → class catalog → session class offerings/sections → subject offerings → staff assignments → admission/enrollment/guardian links → attendance/homework/notices → exams/fees/timetable → reports/storage/notifications.

This milestone groups F015 (bounded future calendar), F018 (classes/sections/subjects) and the supporting workflow UI. Global rules, active-calendar review and school onboarding have separate platform/security dependencies and are not silently implemented here.

| Slice | Deliverable | Acceptance gate | Status |
|---|---|---|---|
| A | Future session and class catalog backend | Current School Admin + MFA, tenant-scoped listing, strict payloads, expiry, Demo caps, audit rollback, idempotency and real MariaDB races | INTEGRATION VERIFIED for supported backend slice atd0534d3; owner review pending |
| B | Session offerings, sections, subjects | Composite tenant FKs, nullable deferred teacher, compulsory/optional subjects, no cross-session mixing | INTEGRATION VERIFIED at884ec68; teacher assignment deferred; full UI/mobile/owner acceptance pending |
| C | Unified web academic setup | Approved visual direction, real API integration, responsive/keyboard/error/conflict states | NOT STARTED |
| D | Flutter setup/read workflow | Same contract; secured CI; milestone APK and meaningful device journey | NOT STARTED |

No edit/delete/activate/close API exists in slice A. Sessions always start in `future`; no automatic activation is implied. Current/active boundary changes remain unavailable until platform approval workflow exists. A missing valid IANA school timezone denies future-session creation; this branch does not invent onboarding/timezone configuration. Class catalog remains independent of sessions.

Demo engineering defaults: at most 10 session records / 50 class records per school, checked under the school lock; zero files remains unchanged. Expired entitlement permits authorized catalog reads but no commands (including retries). No student, guardian or login accounts created.

## Branch strategy and acceptance register

`feature/m2-academic-setup` starts at actual main `c9ae3cd5d5cc2dc7683fe61576fbc851f49b85ff`. No merge/cherry-pick of PR6/7/8 is needed for this backend slice. Those Draft PRs remain independent; their production gates are still closed. Before later integration, verify actual branch tips and resolve source/document overlaps without stale-file replacement. No main merge is authorized.

SEC-01 native vault 6/6 and two-process write/read 1/1 each are owner-reported physical Vivo1951 Android11/API30 results. Real-engine MethodChannel remains DEVICE TEST PENDING (PR7 harness compiled at78e25234320f978bcf4bb1b79867ec7f3884c653). Do not require another APK for this backend-only slice. Secure Flutter authentication acceptance remains blocked by that runtime gate. Flutter3.47.8/Dart3.13.7 and all lockfiles/isolation controls stay unchanged.

Production platform issuer, privileged native MFA, independent lost-factor recovery, real credential delivery, verified-contact infrastructure and hosting verification remain separate approval/dependency gates.

## Subsequent dependency groups

1. Tenant readiness: registration review, private Demo lifecycle, explicit live entitlement and readiness conversion (F007–010). Build before accepting public onboarding; existing synthetic schools do not prove onboarding.
2. Academic setup completion and staff effective-dated assignments (F015/018/019), then student profiles/enrollment/promotion and verified guardian links (F014/016/017).
3. Daily operations: attendance/correction, homework Done, formal notice review (F020–023); immutable authorship and cutoff approval gates.
4. Evaluation: exams/grading/results (F024); separate from later timetable/leave/substitution (F026/027).
5. Separate money milestones: school fee ledger (F025), then platform subscriptions/manual verification/receipts (F011–013); never share ledgers.
6. Private storage/quotas, scoped reports, notification outbox and privacy/operational acceptance (F028–031/035–043). Storage safeguards precede file-enabled workflows. Certificates remain later optional.

See `docs/requirements/IMPLEMENTATION_PROGRESS.csv` for all source feature IDs. NOT STARTED means no executable domain workflow found, even if a designed contract exists.

## Progress methodology

Planning-only effort points and partial implementation credits are explicit per feature in the register. Overall estimate = sum(points × credit/100) / sum(points); credits are engineering estimates, not passed-test ratios or acceptance claims. Broad feature rows remain heavily discounted when only a subset exists. Recalibrate with measured work; no feature is accepted solely by this estimate.

Current milestone planning weights: slice A 25%, B 35%, C 25%, D 15%. A may earn at most 80% of its weight after backend integration checks pass; owner review and later integration remain pending. Therefore even a green slice A is at most 20% of M2, not M2 completion. No physical milestone acceptance claimed.

## Executed slice A evidence

Implementation d0534d33a8b4c2b85c01eda373ce2f64ba70b70d: run40921807983 API/Web/Flutter passed and Integrity40921808036 passed. Real MariaDB125/698 (all14 concurrency cases),18 HTTPS Chromium journeys,16 isolated Flutter tests, migration fresh/rollback/remigrate, Pint and dependency audits passed. Evidence-only successors need their own CI. Main-based counts exclude unmerged PR6/7 additions; no security tests were removed. Slice A earns20% of the milestone planning weight; full M2 and domain UI/mobile remain unaccepted.

## Slice B checkpoint

Four create/list resources implemented with same-school composite references, uniqueness, explicit subject required/optional classification and future-session setup eligibility. Sections defer actual teacher assignment (staff/effective-dated scope unavailable), not class creation. No domain UI, fabricated student counts or automatic enrollment. Optional teacher assignment is a documented dependency, not a silently completed feature. API0.2.0 and migration000008 describe exact fields and additive relationships.

Local PHP8.4.1 suite:149 discovered,125 passed/817 assertions;24 MariaDB-only cases skipped (14 existing +10 new). New tests include9 duplicate/replay/Demo-cap race scenarios and one deterministic queued-command/membership-revocation race. Two new real HTTPS browser scenarios are committed, not locally claimed passed. Full hosted CI must establish actual counts. Web lint/typecheck/build, Pint, Composer validation/audit passed locally. Existing Flutter security procedure and locks unchanged. Final evidence will be recorded on the exact published checkpoint.

Progress credit after supported Slice B backend integration passes: B earns80% ×35%=28%; A retains20%; M2 estimate48%, owner acceptance/UI/mobile still pending. F018 planning credit increases5→35% only after hosted evidence (not full-feature acceptance); total effort denominator remains198 points, yielding about13.4% overall. Subjective planning credits are not a delivery guarantee or an accepted-feature percentage.

## Executed Slice B integration

Implementation884ec68e2e299df564541778a952a4c90f557d42: M1 run40928712658 API/Web/Flutter passed; Integrity40928712670 passed. Real MariaDB149 tests/994 assertions, no skips; all24 concurrency cases (10 new),20 actual HTTPS journeys (2 new),16 isolated Flutter tests; migration fresh/rollback/remigrate including000008; Pint, Composer/npm audits, web lint/typecheck/build passed. See M2_SLICE_B_RESULTS.md. No production/device acceptance inferred. Evidence-only successors require their own checks. Current estimate: M2~48%, overall~13.4%; F018 backend subset is integration verified while domain UI/mobile, teacher assignment and owner acceptance remain pending.
