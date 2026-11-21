# Development baseline report

Audit date: 2026-11-21 (Asia/Kolkata). Documentation branch: `docs/phase-00-development-baseline` from `main@c9ae3cd5d5cc2dc7683fe61576fbc851f49b85ff`.

## Progress

Retained the prior register's 43 effort weights (198 points). Credit reflects the proportion of the approved implementation evidenced by source and tests: 0 for no executable slice credited, 5–30 for an isolated layer or bounded slice, 35–70 for multiple linked layers with significant business or acceptance gaps, 75–99 for near-complete scope, and 100 only for the approved architecture governance scope (F001). Multiply points by credit/100 and divide by 198. Governance and all planned rows, including deferred certificate work, remain in the denominator. Scores are planning estimates, not measured hours or acceptance rates.

- Main has 24.45 credited points (12.3%).
- The cumulative evidenced portfolio has 33.70 (17.0%), with 83.0% uncredited. Credit takes the strongest nonduplicated slices across refs. It does not imply a combined green build.
- The older tracker at the PR13 branch had 27.95 credited points using the same weights. F007 now credits actual intake/status; F015 actual future sessions; F004/F005/F032/F036 platform, tenant and native work; F010/F033/F034/F038/F041 verified incremental workflows and guardrails. Per-row scope and gaps are in the [tracker](../requirements/IMPLEMENTATION_PROGRESS.csv).

## Source and branch evidence

| PR | Base → HEAD | Status / scope |
|---|---|---|
| #6 | main → 3c34bc9 | Draft; platform MFA, assurance, recovery |
| #7 | main → 78e2523 | Draft; native Keystore integration |
| #8 | main → 5276e20 | Draft; validation of #6/#7. Use for evidence, avoid importing both source PRs and the validation branch. |
| #9 | main → 491385b | Draft; academic API, schema and browser workspace |
| #10 | #9 → 19729ac | Draft; registration intake/status |
| #11 | #10 → 9387986 | Draft; synthetic first School Admin bootstrap |
| #12 | #11 → 9cf1d93 | Draft; pending proof reissue |
| #13 | #12 → 94d1554 | Draft; synthetic staff sealed activation and web patch |

GitHub returned no PR metadata for #14–20 during this pass. Remote and visible local refs did not contain either reported Academic Mobile branch. Verified Android scope includes the native Keystore vault and instrumentation tests on #7/#8 and existing Flutter auth sources. Prior Vivo evidence does not meet the current OPPO gate. The visible earlier worktrees contain staged or unstaged changes. This audit used a new clone and left them intact. Every previously visible worktree HEAD was found in fetched remote history. The staged or unstaged deltas are not counted as published commits and need individual review before reuse. Offline branches on other machines were not observable.

A read-only merge-tree simulation of #13 with #8 shows conflicts in Laravel browser routes, web browser tests, migration documentation, CHANGELOG and current handoff. Integrate on a dedicated `integration/edu-development-baseline` branch only after separate authorization. Start at current main, integrate reviewed #6 and #7 once, then the reviewed #9→#13 stack in order. Compare the #8-only Android build and instrumentation changes and selectively import them if required, with review. Resolve route/MFA policy and tests before accepting the baseline. Verify exact source refs, clean migration rehearsal, MariaDB concurrency, real HTTPS browser journeys and isolated Flutter CI. Do not change main or retarget the existing Draft PRs. Branch `feature/m3-platform-school-onboarding` from the accepted integration SHA.

## Readiness and limits

- Main locks Laravel 13.39.0 and Next.js 16.4.3; PR #13 locks Laravel 13.43.0 and Next.js 16.4.6. Composer requires PHP ^8.4.1; CI pins PHP 8.4, MariaDB 10.11, Node 24 and isolated Flutter 3.47.8/Dart 3.13.7. The unit test harness defaults to disposable SQLite `:memory:`; CI exercises MariaDB migrations and MariaDB-only behavior. Keep synthetic keys and credentials confined to disposable test runs.
- Exact-head hosted evidence: PR #8 [M1](https://github.com/mollanuramin130/NUVIKOZ_Edu/actions/runs/40892765495), PR #9 [M1](https://github.com/mollanuramin130/NUVIKOZ_Edu/actions/runs/44786880537), PR #13 [M1](https://github.com/mollanuramin130/NUVIKOZ_Edu/actions/runs/44902351034) and corresponding integrity checks passed. These runs predate this docs branch and cover their own heads only.
- Local web: `npm ci --no-audit --no-fund`, lint and typecheck passed on main. The build compiled and generated all 13 static pages, then exited 1 at the Next.js 16.4.3 security gate. Preserve the existing reviewed 16.4.6 patch from #13 in the integration candidate and require a clean build/audit there.
- Local runtime: PHP/Composer and ADB were not exposed in the current execution environment. No local PHP suite or authorized device test was run. Flutter execution remains on the repository's credential-free, metadata-blocked CI path. Device gate: verify both serial `EM9D5L998XB6599H` and model `CPH2603` before installing or executing only on the authorized OPPO.
- Staging staff activation: prior run stopped at PHP CLI Sodium preflight; hPanel selection differed from observed web module evidence. Keep the hosted blocker for final runtime validation. No Hostinger or Nursery operation is part of Phase 00.
- Missing canonical storage filename is an import-name difference. The retained source is `docs/security/STORAGE_POLICY_v1.1.md`, identified in the import manifest. Effective corrections take precedence over superseded source text.

Phase 01 specification is ready. Implementation against a shared baseline depends on owner authorization to consolidate the listed Draft source branches on the isolated integration branch. Local design and contract review are independent of that authorization.
