# Phase 01 implementation plan

Start from the owner-authorized, CI-green `integration/edu-development-baseline` commit. Use `feature/m3-platform-school-onboarding` and a Draft PR. Reuse the platform identity from #6 and registration intake/status from #10; staging synthetic provisioning from #11–13 is test evidence. Keep all delivery faked in the phase.

## Implementation order

| Increment | Requirements and feature IDs | Implementation and acceptance |
|---|---|---|
| 01A — platform authority | E-001–005, I-001–002; F004/F005 | Review first factor, current MFA assurance, session binding and recovery ceiling. Keep platform context separate from school membership. Restore existing tests and add end-to-end platform-versus-school denial when integrating. Use synthetic verified principals. Real issuer and recovery delivery are release dependencies. |
| 01B — registration review | F-001–003; F007 | Extend the intake schema with review state and immutable decision history according to the contract. Add platform list/detail and authorized review commands plus role-scoped web review and typed private status. Verify concurrent decisions, exact state transitions, audit rollback, cross-context denial and no PII in public status. Implement verified-applicant dependency with a fake issuer in tests. |
| 01C — private Demo and safe conversion | F-001–003, G-001–003, V-001–002, COR-D05 and applicable COR storage rows; F008/F009/F040/F041 | Implement governed 30-day Demo issuance/expiry, synthetic first access, isolated labeled read-only samples and idempotent conversion under the approved contract. Enforce zero Demo uploads through API and workers. Preserve historical records and exclude shared sample ownership. Test resource caps and expiry with a controlled clock. |
| 01D — explicit activation and entitlement | G-001–003; F010 with F004/F005 | Keep approval, access activation and subscription/payment as independent states. Permit authorized free activation as contracted; preserve authorized reads after expiry and block ordinary writes. Add audited explicit commands, web action states and transactional/idempotent tests. Defer money ledgers and renewal delivery to Phase 06. |

Use the [dependency matrix](MODULE_DEPENDENCY_MATRIX.md) for all exact requirement IDs and separate approved corrections. Use MariaDB integration, real HTTPS browser checks, and existing credential-free Flutter CI. Keep privileged platform operations scoped to their approved channel. For school roles, wire only the Android scope already specified by the contract; test authenticated tenant handling with fixtures and reserve physical acceptance for the authorized OPPO. Avoid changing academic catalog/staff assignment and admission workflows in this phase.

## Exit criteria

Platform reviewer can complete a synthetic registration review and explicit, authorized Demo/live activation in a disposable environment. Tenant isolation, MFA, audit changes, conversion idempotency, lifecycle independence and Demo write guards are exercised on the integrated SHA. No real messages, payment, hosted migrations or deploys. Update the tracker and stop for owner review before Phase 02.
