# M1 — Multi-Tenant Foundation, Authentication, RBAC, Laravel API Foundation and CI

Status:READY FOR IMPLEMENTATION AFTER REPOSITORY INITIALIZATION. This plan is owner-authorized preparation;no application module is claimed implemented. Estimated effort3–5engineeringweeks subjecttoruntime/dependencyaccess.

## Implementation-ready task sequence

| Task / dependency | Deliverable and target files | Acceptance criteria / required evidence |
|---|---|---|
| M1-01 Runtime/dependency gate;no predecessor | Record PHP CLI/FPM target,Composer2,compatible Laravel13/PHPconstraint,Node/Next/Flutter/Dart/MariaDB versions in deployment/RUNTIME_MATRIX.md. Pin app lockfiles after scaffold. | PHP/Composer/MariaDB test runtime installed in isolated dev/CI;version commands recorded;no Hostinger access required. Avoid unsupported reference-doc8.3 assumption. |
| M1-02 API scaffold;01 | apps/edu-api Laravel app,health route,JSON errors,request IDs,.env.example,tests/PHPUnit config. | Composer lock committed;health and422/401 envelopes tested;no real DB credentials;development key generated outside Git;formatter/build checks recorded. |
| M1-03 Tenant/identity migrations;02 | users,schools,memberships,identifier/token/session and permission models/migrations plus synthetic factories. | Immutable IDs and unique school identifiers;school-scoped composite FKs reject cross-school relations;MariaDB fresh+migrate+rollback rehearsal on disposable DB;no cascade deleting history. |
| M1-04 Tenant context services;03 | Explicit TenantContext resolver,membership middleware,tenant query boundaries and job context envelope. | SchoolA cannot accessSchoolB via guessed ID,alteredschool_id,missingcontext,role-switch,job/export/downloadboundary;inactive membership denied;no global singleton tenant leak. |
| M1-05 RBAC and provisioning;04 | Sixrole enum,capability map,policies,SA-only provisioning service,permission cap/revocation,audit. | Principal can create admissionprofile fixture but cannot provision/activate logins;SAcannotgrantPSAorforbiddenpowers;lastSAprotected;permissionchangeaudit;backenddenialindependentofUI. Profile module may use contract stub until M3;do not falsely ship admissions. |
| M1-06 Browser/mobile auth;03–05 | Sanctumcookie/CSRF+host-onlysessioncookie integration,exactCORS,per-device opaque tokens,activation/passwordset/recovery/logout. | Student assignedID+schoolcode/noemail;sharedphoneambiguity handled;random provisional secret/hash-only;activation single-use/expiry;rotation/revocation;noJWTrequirement;CSRFnegativeanddeviceTTLtests;noNursery-widecookie. |
| M1-07 Stage/entitlement guard skeleton;04–06 | Centralwrite classifier,Demozero-fileguard,expired/suspendedaccess policy,capabilityDTO. | SyntheticDemo forbids multipart/raw/base64/import/remote/job file intent;non-file action allowed;expirednormalwrites denied;historicalread allowed whenresourceauthorized;onlynarrowrenewal/securityexceptions;no proofcapbypass. No financial engine implemented inM1. |
| M1-08 CI and contracts;02–07 | APIlint/unit+MariaDBintegration+contractjob,secret scan andmigration checks;document scripts. | Existingrepository-integritypasses;allnewauth/tenant/roletestsactuallyrun;failedcheckblocksPRperavailableprotection;no production deploy/workflow secrets. |
| M1-09 Review/handoff;08 | Threat-reviewnotes,coverage/migrationinventory,READMEcommands,CURRENT_STATUS,PRacceptanceevidence. | Exactcommands/results,knownrisks,nextsafetask,compatibleOpenAPIupdates;independentreviewifavailable;no unexecutedtestsmarkedpassed. |

## Parallel work boundaries (only when explicitly delegated)

One owner per bounded area after dependency contracts settle. Migration/schema owner serializes schema changes. Web/Flutter may prepare auth DTOs and mocked route states after API contracts stabilize;they cannot invent divergent auth or quota rules. No spontaneous multi-agent edits to the same files. Every agent resumes from canonical status and checks actual git state.

## Test fixtures and release gate

SyntheticSchoolA/B,tenant-switchuser,SA/P/T/S/G,revokedguardian,inactive staff,lastSA,sharedphone,verified/unverifiedcontact,Demo/free/expired/suspendedcontexts. Freeze school-local clock where required. CI must use MariaDB for composite constraints;SQLite alone is insufficient.

M1 complete only when runnableAPI+documentedlocks+actualunit/integration/contractresults support auth,tenant andRBAC claims. Web/mobile scaffolding can follow in separate bounded PRs;their app builds cannot be inferred from API tests. No production migration/deployment permitted. M2 follows with onboarding/demo/subscription/filefoundations;do not jump into broad ERP modules before M1 gates pass.

## Next recommended bounded task

M1-01: establish isolated PHP/Composer/MariaDB runtime and record compatible versions;then M1-02 Laravel scaffold with health/error-contract tests. CurrentinitenvironmenthasNodeandPythonbutnoPHP/Composer/Flutter. Avoid an untestable handwritten Laravel shell.
