# Agent operating contract

Before coding, read README, docs/agent-handoffs/CURRENT_STATUS.md, active milestone, relevant ADRs, OpenAPI and the database dictionary. Inspect git branch/status and migration state. Take a bounded task with acceptance criteria.

Enforce tenant/resource/role/stage policies in the backend. Demo permits no user files or tenant-generated physical documents. Free Live PDF limits apply together; Paid policies are versioned PSA controls. Student homework uploads remain disabled. Never change Nursery production assets.

Run appropriate format/lint/test/build checks; record exact outputs or NOT RUN with reason. Update SRS, API, dictionary and ADR when behavior changes. Update canonical handoff with timestamp, task, changed files, branch/commit, migrations, commands/results, blockers, risks and NEXT SAFE TASK.

Do not force push, overwrite another agent's uncommitted work, refactor unrelated code, expose secrets/real child data or invent success. Keep repository creation, production deployment, purchases and real messaging approval gates distinct. All agents resume from repository evidence, not prior chat claims.

## Development and review commands

Run API commands from `apps/edu-api`: `composer install`, `composer audit --locked`, `composer check-platform-reqs`, `vendor/bin/pint --test`, `vendor/bin/phpunit --display-warnings --fail-on-warning`. Tests default to disposable SQLite; MariaDB CI overrides DB variables. Never run `migrate:fresh` on real data. Use the migration principal only for schema/trigger changes; future runtime credentials must not have DROP/ALTER/TRIGGER authority.

Run web commands from `apps/edu-web`: `npm ci`, `npm audit --audit-level=high`, `npm run lint`, `npm run typecheck`, `npm run build`. Role shells alone do not protect data.

Flutter verification is authorized only in the metadata-blocked, credential-free hosted CI procedure in `scripts/verify_flutter_isolated.sh`. It must establish kernel denial before executing the checksum-verified SDK, then drop capabilities and privilege escalation. Do not run the old unrestricted local command, probe metadata endpoints or omit the network controls. See `docs/security/FLUTTER_METADATA_INVESTIGATION.md`.

School device tokens require the exact school capability and current membership; browser sessions need a separate context binding in increment 2. Privileged provisioning services validate commands independently of HTTP requests. Audit row triggers supplement model guards. Never grant a school token platform capability.

Review ADR-020, the latest test evidence and `docs/milestones/M1_AUTH_INCREMENT_2.md` before continuing. Work on feature branches, inspect actual remote HEAD and concurrent work, and keep approved target contracts distinct from implemented subsets. A ready-for-review PR is not permission to merge. Request owner authorization for any merge; production actions remain unauthorized.
