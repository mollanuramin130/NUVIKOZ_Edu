# Module definition of done

1. Link approved requirements, feature contracts, dependencies and an isolated development baseline. Record intended web and Android role capabilities and explicit exceptions.
2. Implement the applicable migration and database constraints, business service, REST contract and error/empty states. Rehearse fresh, rollback and forward migrations against disposable MariaDB when schema changes.
3. Enforce current identity, role, tenant and entitlement at server entry points. Include cross-tenant and unauthorized requests, audit rollback, idempotency and concurrency cases where relevant.
4. Verify the full applicable web and Android workflows against the API. Screens or navigation alone earn partial credit. Use synthetic actors and fake delivery.
5. Pass relevant unit, integration, regression and security checks on the exact candidate SHA. Obtain code review. List unexecuted checks and explain why they remain pending.
6. Update the feature tracker, contract, schema and handoff as applicable. Record tested refs and the next dependency. Keep work in a Draft PR until the applicable review allows promotion.
7. When required, run physical acceptance only on OPPO F25 Pro (CPH2603, serial EM9D5L998XB6599H). Verify both over ADB before executing or installing. If disconnected, record PENDING OPPO and continue eligible offline tests. Preserve user data. Emulator runs are outside scope.
8. Mark environment-dependent checks PENDING RELEASE VALIDATION. Hosted deployment has its own owner approval, rehearsed restoration, backup and rollback evidence.
