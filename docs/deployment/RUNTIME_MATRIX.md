# M1 runtime evidence

| Component | Actual observation | Limit |
|---|---|---|
| PHP local |8.4.1 CLI,workspace-local Herd/php.new binary |Used only for synthetic tests;patch is old,not production-approved. No hostFPM check. |
| Composer local |2.10.3 |Platform requirements checked against lock. |
| Laravel |13.39.0 resolved |Official skeleton13.x;project requiresPHP^8.4.1,matching Nursery family/declared minimum. |
| MariaDB local |10.11.14 executable prepared |Database initialized in private workspace;server cannot open UNIX socket(Operation not permitted). No localMariaDBtestpass claim. |
| Next.js/React |16.4.3 /19.2.8 |Actual npm lock,typecheck and build;Node24.22.1. |
| Flutter/Dart |SDK download attempted;verification BLOCKED |Automatic review rejected metadata endpoint169.254.169.254 access. No runtime/analyze/test/build/lock claimed. Dart source constraint>=3.10.9. |
| CI |PHP8.4 + MariaDB10.11 service;Node24 |Isolated GitHub runner;result must be observed on PR/head. |

System package installation initially failed because apt could not write system cache;private download/extraction avoided system installs. PHP/Composer were installed into scratch,not Hostinger. MariaDB binaries and datadir are outside the repository. Temporary dependencies/vendor/node_modules are ignored. No production resources or credentials used.

Pinned CI actions were resolved from official public Git tag refs using git ls-remote:setup-phpv2=d52fc211a0436f8839a587b3b5b8efddab4744ea;setup-nodev6=249970729cb0ef3589644e2896645e5dc5ba9c38. Checkout pin verified in prior increment. Flutter runtime must not be retried indirectly without resolving the automatic review block.
