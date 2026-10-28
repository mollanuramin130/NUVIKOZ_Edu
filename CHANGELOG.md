# Change log

## 2026-10-28 — Gate3 initialization

- Created owner-authorized private repository,default branch main.
- Imported approved corrected v1.1 requirements,architecture,database/API contracts,storage policy,test design and shared sample assets.
- Added documentation-first monorepo directories,agent guidance,integrity checks and non-deploying CI.
- Prepared M1 task breakdown. Application frameworks and runtime tests have not been implemented.

## M1 increment1 —2026-10-28

ActualLaravel13.39.0API andinitialtenant/identitymigrations;tokenauth,JSONcontracts,requestIDs,scope/roleguards,SAstaffprovisioning,append-onlyaudit,idempotencypointers and29meaningfulfeaturetests. Next16.4.3TypeScriptsixrolescaffoldtypechecked/built. Flutterminimalpackagemanualsource;runtimeverificationblocked. IsolatedMariaDBCIconfigured;resultrecordedseparately. No productionchanges.

## M1 foundation security review — 2026-10-28

Bound school tokens to the selected tenant; separated platform token authority; denied suspended school login. Added independent provisioning service validation and actor rechecks, database audit UPDATE/DELETE triggers, protocol error headers, and stricter mobile origin validation. API regression suite passed 37 tests / 99 assertions locally and in MariaDB CI. Composer/npm advisory scans found no known vulnerabilities at review time.

Investigated Flutter Azure bot detection and verified the scaffold in a checksum-pinned, metadata-blocked, privilege-dropped hosted CI job: Flutter 3.47.8 / Dart 3.13.7, dependency resolution, analysis and 3 tests passed. No mobile release build claimed. Prepared a separate browser authentication/account lifecycle increment with dependencies and acceptance criteria. No merge or production change.
