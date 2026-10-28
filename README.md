# NUVIKOZ Edu

Public modular monorepo for an Indian school/institute management SaaS. Corrected architecture baseline **v1.1 is owner-approved**. Repository creation and initialization are authorized; production deployment remains unauthorized.

## Start here

1. [AGENTS.md](AGENTS.md): contribution/agent rules.
2. [Current status](docs/agent-handoffs/CURRENT_STATUS.md): verified progress and next safe task.
3. [Architecture](docs/architecture/ARCHITECTURE_v1.1.md) and [effective requirements](docs/requirements/15_OWNER_CONFIRMED_REQUIREMENTS.md).
4. [Storage policy](docs/security/STORAGE_POLICY_v1.1.md) and [API contract](packages/contracts/openapi.json).
5. [M1 implementation plan](docs/milestones/M1_FOUNDATION.md).

## Structure and implementation status

- apps/edu-api: Laravel13.39.0/PHP REST foundation;see apps/edu-api/README.md.
- apps/edu-web: unified Next.js16.4.3/TypeScript six-role scaffold;typecheck/build executed.
- apps/edu-mobile: minimal Flutter/Dart source scaffold; dependency resolution, analysis and three tests passed in metadata-blocked CI. Android platform generation/build remains pending.
- packages/contracts: approved target contract plus the implemented 14-route M1 API subset; no generated clients claimed.
- docs: approved design, governance, task plan and clearly separated historical evidence.
- assets/demo-samples: seven synthetic shared read-only PDF assets; no real personal data.
- scripts: executable repository integrity validation; tests: runtime-test plan.

The planned API is a modular monolith with a dedicated Edu MariaDB database and strict school tenancy. Six initial role types. Hosting compatibility is unverified; no Nursery or Hostinger production asset is changed.

## Fixed product rules

Demo:30 days,0 user file uploads,interactive non-file records within controlled limits,shared read-only sample previews. Free Live:5,000,000 bytes/PDF,50 active uploaded PDFs,10 new PDF uploads/school-local day,500,000,000 total school bytes,warn80%,block100%. Paid policies versioned/configurable by PSA. Student homework uploads disabled in every phase. Account provisioning,attendance cutoff,notice review,financial ledgers and all approved rules remain authoritative in requirements.

## Checks available now

Python3 standard library only:

```bash
python3 scripts/verify_repository.py
```

This validates imported document bytes,JSON/contracts references,CSV structure,sample hashes and obvious secret/file hazards. It is **not** an application build, tenant security test, formal OpenAPI validator or exhaustive secret audit. PHP/Composer were installed in an isolated workspace for M1;Flutter verification now runs only in metadata-blocked hosted CI. See M1 for planned Laravel tests and setup gates.

## History and authority

The architecture snapshot preserves earlier approval-request wording as source evidence; the owner's Gate3 approval in docs/decisions/GATE3_APPROVAL.md supersedes those workflow statuses. Obsolete demo policies and past readiness reports live under docs/historical and cannot define current behavior. Repository source and canonical handoff,not prior chat,are the continuation authority.
