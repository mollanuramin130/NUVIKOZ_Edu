# Agent operating contract (proposed)

Before coding, read README, docs/agent-handoffs/CURRENT_STATUS.md, active milestone, relevant ADRs, OpenAPI and the database dictionary. Inspect git branch/status and migration state. Take a bounded task with acceptance criteria.

Enforce tenant/resource/role/stage policies in the backend. Demo permits no user files or tenant-generated physical documents. Free Live PDF limits apply together; Paid policies are versioned PSA controls. Student homework uploads remain disabled. Never change Nursery production assets.

Run appropriate format/lint/test/build checks; record exact outputs or NOT RUN with reason. Update SRS, API, dictionary and ADR when behavior changes. Update canonical handoff with timestamp, task, changed files, branch/commit, migrations, commands/results, blockers, risks and NEXT SAFE TASK.

Do not force push, overwrite another agent's uncommitted work, refactor unrelated code, expose secrets/real child data or invent success. Keep repository creation, production deployment, purchases and real messaging approval gates distinct. All agents resume from repository evidence, not prior chat claims.

## M1 development commands

Run API commands from apps/edu-api:composer install,composer check-platform-reqs,vendor/bin/pint --test,php artisan test. Tests defaultSQLite;MariaDBCI overridesDB_* on a disposable service. Never migrate:fresh on live data. Web:apps/edu-web npm ci,npm run typecheck,npm run build. Flutter runtime checks are blocked by auto-review(metadataaccess);do notretryindirectly orclaimpassed. Allsourcechangesonfeaturebranch;draftPR,nomerge. Keepapprovedtargetcontractunchangedanddocumentimplementedsubsets.
