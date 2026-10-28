# Branch and CI plan

Default branch main; small feature branches; reviewed PRs; no force push. Proposed protection:require PR,one approval where practical,resolve conversations,require the repository-integrity check,block force pushes/deletion,then add PHP/MariaDB/auth checks when implemented. Account/private-plan enforcement has not been enabled or claimed; do not configure nonexistent checks.

CI uses read-only contents permission,no deployment secrets and no persistent Git credentials. It runs Python standard-library repository checks only. Initial checkout v4.2.2 produced a Node20deprecation warning despite a successful run. It was replaced with verified official checkout commit de0fac2e4500dabe0009e67214ff5f5447ce83dd,whose action.yml specifies node24. Runner is explicitly ubuntu-24.04 to avoid ubuntu-latest migration drift. This is a deliberate verified pin,not a latest-release claim.

First remote validation passed on commit6937ea1352ad6d4d26c9315b1a87092a73496fde,Actions run40680629060:39import hashes,7samples,145APIoperations,1451schema refs,10CSVfiles,no checked secret patterns. Application tests NOT RUN. Final updated workflow result must be observed separately.

No automatic production deploy,purchases,real mail,external data or secret upload. Later Laravel CI requires actual dependency locks and MariaDB integration fixtures. Branch protection remains a documented proposal until supported settings are applied and verified.
