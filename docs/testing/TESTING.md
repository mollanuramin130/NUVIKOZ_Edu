# Validation and test status

Available now:

```bash
python3 scripts/verify_repository.py
```

Checks required initialized paths,imported document hashes,shared sample hashes,local OpenAPI references,CSV parsing,fixed policy fixture and obvious secret/file patterns. It does not validate full OpenAPI semantics or prove application security. 06_TEST_MATRIX.csv contains80designed acceptance entries,not passed business tests.

M1 adds service/policy unit tests,MariaDB migrations/tenant integration,auth/CSRF/device token and role/provisioning tests,then OpenAPI contract checks. Later:quota/concurrency/expiry/outbox,PDF content,Playwright role journeys,Flutter device tests,accessibility and isolated load/restore drills. Synthetic A/B schools only;no real minors/production backups.

Once implemented,the expected commands are API Composer install from lock,Pint and php artisan test;web npm ci,lint,typecheck,test,build and Playwright;Flutter pub get,format check,analyze,test and device integration. Do not claim these commands exist/pass before scaffolding. Initialization environment lacks PHP,Composer and Flutter. No production load/security scan is authorized.
