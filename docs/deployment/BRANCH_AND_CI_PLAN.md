# Branch and CI plan

Defaultmain;smallfeaturebranches;reviewedPRs;noforcepush. Proposedmainprotection:requirePR,oneapprovalwherepractical,resolveconversations,requiredrepository-integritycheck,blockdeletion/forcepush,andlaterLaravelMariaDB/authchecks. Do not block recovery with unavailable checks. Account/private-repo plan support remains to be checked;this file does not claim branch protection is enabled.

CI is repository-integrity only,read-only contents permission,no secrets,one pinned official checkout action with persist-credentials:false,Python standard-library validation. Checkoutcommit11bd71901bbe5b1630ceea73d27597364c9af683 wasverifiedthroughGitHubasv4.2.2;it is a deliberate pin,not a claim of latest release. No automatic deploy,purchases,external mail or secret upload. Future action upgrades are reviewed separately.

Local checks are authoritative only for their named scope. GitHub Actions result must be observed after import;never infer passed from a workflow file. AddPHP/MariaDBCIonlyafteractualfoundationcodeanddependencylocks.
