# Validation and test status

Available now:python3scripts/verify_repository.py (add the space when running: `python3 scripts/verify_repository.py`). Integrity checks only;no app runtime tests exist. 06_TEST_MATRIX.csv contains designed acceptance cases,not passed tests.

M1 adds policy/service unit tests,MariaDB migrations/tenant integration,auth/CSRF/device token androle/provisioning tests,OpenAPI contract checks. Later milestones add real quota/concurrency/expiry/outbox/PDF tests,Playwright role journeys,Flutter tests,accessibility and isolated load/restore drills. Synthetic A/B tenants only;no real minors or production backups.

Future API commands (once scripts exist):composerinstallfromlock,pint--test,phpartisantest. Web:npmci,lint,typecheck,test,build,Playwright. Flutter:pubget,formatcheck,analyze,test,deviceintegration. Do not run placeholders and claim success. Current environment lacksPHP,ComposerandFlutter;installation must precede application tests. No production scans/load authorized.
