# Repository creation readiness — Gate3 preparation

28 October2026 (Asia/Kolkata). External changes: **NONE**.

| Item | Verified observation or proposed setting |
|---|---|
| GitHub authentication | get_user_login returned `mollanuramin130`, userID142964939. |
| Existing repository access | Connector returned admin on the Nursery reference repository. Read-only permission check; no modification. |
| Proposed owner | `mollanuramin130`, to be explicitly named in the owner's creation approval; no organization silently selected. |
| Proposed repository | `NUVIKOZ_Edu`, **Private**. |
| Name check | Exact repository lookup returned404 NOT_FOUND. An authenticated owner-affiliation listing returned21 visible repositories and no NUVIKOZ_Edu match. This is not conclusive availability evidence; access scope can also cause404. Recheck at creation. |
| Creation capability | Current GitHub tools expose no create_repository operation. Local `gh` CLI is absent. Existing-repo write access does not prove account-level creation rights. |
| Initial branch | Proposed `main`; short feature branches and reviewed PRs. No branch exists yet. |
| Visibility/protection | Proposed private and required checks; actual account/plan support and resulting settings must be verified after authorized creation. |
| Readiness | Corrected design and documentation preparation complete. Creation remains blocked pending explicit approval **and** an available authorized creation method. |

After approval, recheck owner/name/conflicts and use a supported creation operation. If unavailable, the concrete owner fallback is GitHub → New repository → Owner `mollanuramin130` → Name `NUVIKOZ_Edu` → Private → initialize README with default branch main, then provide the resulting repository for a verified commit/import. Never overwrite an existing repo. Browser fallback needs the applicable authorization and available capability; it has not been attempted here. Do not request tokens or passwords in chat.

Once actual metadata/private/default branch are verified, commit approved documentation/contracts and proceed with the approved foundation scaffold. Report only the actual repository URL and commit returned by tools. Creation approval is not production deployment, DNS/database mutation, spending, real messaging or mobile publishing approval.

## Final repository structure

```text
NUVIKOZ_Edu/
  apps/edu-api/                        Laravel/PHP; scaffold after approval
  apps/edu-web/                        Unified Next.js TypeScript
  apps/edu-mobile/                     Flutter/Dart
  packages/contracts/                 OpenAPI and compatibility
  docs/requirements/
  docs/architecture/
  docs/database/
  docs/api/
  docs/security/
  docs/ui-ux/
  docs/testing/
  docs/deployment/
  docs/decisions/
  docs/agent-handoffs/CURRENT_STATUS.md
  assets/demo-samples/                 Shared synthetic assets/manifest
  scripts/
  tests/
  .github/workflows/                   CI; no automatic production deploy
  AGENTS.md
  README.md
  CONTRIBUTING.md
  CHANGELOG.md
  CURRENT_STATUS.md                   Pointer to canonical handoff
```

Prepared documentation templates live under repository-preparation/. They are not an initialized git repo, application scaffold or external commit. Canonical status is docs/agent-handoffs/CURRENT_STATUS.md; the root pointer avoids conflicting copies.

Every ChatGPT Work/Cursor/Gemini/human contributor reads AGENTS, README, status, active milestone, ADRs and contracts; checks branch/status/migrations; takes a bounded task; records exact validation outputs or NOT RUN; updates contract/schema/SRS/handoff when behavior changes. No force push, secrets, real child data, unrelated refactors or unverified claims. Create sanitized env examples and lockfiles only with actual scaffolding; never copy Nursery credentials.

## Outstanding high-impact approvals

1. One explicit owner approval for corrected baseline and private repository creation under the named owner.
2. Actual creation capability, name availability, private visibility and initial branch verification at the action.
3. Separate staging/production Hostinger, DNS, live DB migration and deployment authorization, after compatibility/headroom evidence.
4. Real minors' privacy basis/consent/retention, legal issuer data, support break-glass policy, off-host backup destination and key custody before pilot.
5. Separate paid services/purchases, real outbound messaging and Play Store publication authorization when concrete.
