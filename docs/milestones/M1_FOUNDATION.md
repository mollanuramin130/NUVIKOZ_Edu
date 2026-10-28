# M1 — Multi-Tenant Foundation, Authentication, RBAC, Laravel API Foundation and CI

Status: **In progress — first foundation increment implemented and security reviewed in PR #1**. The task table remains the full M1 acceptance plan; it does not claim that every row is complete. Current evidence is in `docs/testing/M1_SECURITY_REVIEW.md`. Browser auth, lifecycle, MFA and delegated permissions remain incomplete.

## Ordered tasks and acceptance gates

| Task / dependencies | Deliverables and target area | Acceptance evidence |
|---|---|---|
| M1-01 Runtime gate;none | Record isolated PHP,Composer,MariaDB and compatible Laravel constraints in docs/deployment/RUNTIME_MATRIX.md. Check Node/Next/Flutter/Dart constraints for later clients. | Actual version outputs;PHP/Composer and MariaDB available in dev/CI;no production Hostinger connection. Composer manifest,not stale PHP8.3 reference docs,determines compatibility. |
| M1-02 API scaffold;01 | apps/edu-api Laravel app,Composer lock,health route,request IDs,JSON error envelope,sanitized .env.example,PHPUnit configuration. | Install from lock,Pint check and actual health/validation/error tests pass. No real DB credentials,key or .env committed. |
| M1-03 Tenant and identity schema;02 | Schools,global users,school memberships,identifiers,sessions/tokens,permissions and synthetic factories. | MariaDB fresh migration and disposable rollback rehearsal. Immutable IDs,tenant-unique display IDs,non-null school scope and composite FKs reject cross-school references. No destructive history cascade. |
| M1-04 Explicit tenant context;03 | Membership/context resolver,tenant middleware,scoped service/query boundaries and job context envelope. | Synthetic A cannot read/write B by guessed IDs,altered school_id,missing context or role switching. Inactive memberships denied. Jobs/export/download boundaries have explicit guard tests;no shared singleton tenant state. |
| M1-05 RBAC and provisioning;04 | Six role types,operation capabilities,policies,SA-only login provisioning,capped delegation,permission audit and revocation. | Principal cannot create/activate logins. SA cannot create PSA or delegate forbidden powers. Last SA protected. Server checks work independently of UI. Profile/account distinction tested with synthetic fixtures without claiming full admissions is shipped. |
| M1-06 Browser/mobile auth;03–05 | Sanctum browser sessions/CSRF,host-only cookies,exact CORS,device opaque tokens,activation/password set,recovery/logout and revocation. | Student assigned ID+school code works without email;shared phone ambiguity safe;random provisional secrets hashed;activation single-use/expiry tested. Device TTL,logout,reset revocation and cross-subdomain CSRF integration pass. No broad Nursery cookie or JWT copy required. |
| M1-07 Stage/entitlement guard;04–06 | Central write classification,Demo zero-file guard,expiry/suspension gates and capability DTO. | Demo multipart/raw/base64/import/remote/job file intents denied;authorized non-file command allowed. Expired ordinary writes blocked,authorized history reads allowed. Renewal/security exceptions narrow;no proof-upload quota bypass. Financial engine/file upload module not claimed complete. |
| M1-08 Executable application CI;02–07 | API lint/unit tests,MariaDB integration,contract/migration checks and dependency scanning documented as real scripts. | Existing repository-integrity check stays green. New tenant/auth/role tests actually run. No production secrets,deploy workflow or real notification service. Branch protection proposal updated to the actual check names. |
| M1-09 Review and handoff;08 | Threat review,coverage/migration inventory,README setup commands,PR evidence and canonical status. | Exact commands/results and not-run limits;compatible contract changes;known risks and next safe task. Independent review when available,never invented. |

## Work boundaries

Keep one owner per bounded task if delegation is explicitly requested. The schema owner serializes migration changes. Web/Flutter may prepare auth DTOs and mocked route states after API contracts stabilize;they must not invent different auth or quota rules. Every agent reads AGENTS,README,status,M1,ADRs,contracts and dictionary,then checks actual git status/branch/migrations before work.

No broad ERP implementation in M1. School fee ledger,manual SaaS billing,full academic workflows and actual file handling are later milestones. Preserve the finalized six-role,SA provisioning,teacher approval/cutoff,Demo0files,Free5MB/50/10/500MB,Paid policy and student-no-upload requirements.

## Fixtures and completion gate

Synthetic SchoolA/B;a multi-school user;SA/P/T/S/G;inactive staff;revoked guardian;last SA;shared phone;verified/unverified contacts;Demo,free,expired and suspended contexts. Freeze server time where relevant. Test composite constraints on MariaDB,not only SQLite.

M1 is complete only after runnable API,committed dependency locks and executed unit/integration/contract checks support the tenant/auth/RBAC claims. Web/mobile builds need their own scaffolds and evidence. No production migration,deployment,DNS,purchase,real message or app-store publication is authorized.

## Next bounded increment

Continue with [M1 authentication increment 2](M1_AUTH_INCREMENT_2.md) after owner-approved review/merge of PR #1. Its dependency and acceptance matrix covers Sanctum browser cookies, exact CORS/CSRF, Next.js integration, activation/recovery, privileged MFA, revocation, school-specific authority, negative tests and mobile integration preparation. Recommended branch: `feature/m1-browser-auth-account-lifecycle`.

Completed evidence supports API bootstrap, initial identity schema, explicit tenant/token boundary, basic staff provisioning, audit row protection and isolated CI. Full operation capabilities, last-SA mutations, browser/mobile lifecycle authentication and production compatibility are still pending. Do not restart the scaffold or report all M1 tasks complete.
