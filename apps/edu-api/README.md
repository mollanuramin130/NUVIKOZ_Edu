# Laravel API foundation — M1 increment1

Laravel13.39.0 resolved in composer.lock;PHP^8.4.1. Local PHP8.4.1/Composer2.10.3 used for synthetic dev tests. This old local PHP patch is not a production recommendation;CI uses available8.4patch. Hostinger PHP/FPM and production compatibility remain unverified.

From this directory:composer install;vendor/bin/pint --test;php artisan test. PHPUnit defaults to in-memorySQLite. SetDB_CONNECTION=mysql and dedicated disposableDB_* variables for MariaDB. Never run migrate:fresh against real data. .env.example is sanitized;generate a localAPP_KEY for an actual dev server;never commit.env.

Implemented:health/readiness,JSON envelopes/request IDs,no-store,token login/me/logout/password rotation,explicitX-School-ID membership context,scoped school reads,protected platform health,SA-only teacher/principal provisioning,append-only model audit and idempotency pointers. Provisional secret is returned once and never stored plaintext;replay returns pointer plus nullsecret. Child/guardian provisioning awaits verified profile/link services;no unrestricted child account endpoint.

Central scope checks apply in middleware and provisioning service;school/membership IDs immutable in models;composite DB permission references enforce school scope. Demo file/ingestion routes hard-deny;Live file features also disabled here. Quota counter/upload implementation belongs to later milestones;approved fixed policy fixture remains unchanged.

Not complete:browser cookie/CSRF auth,email/mobile verification,activation/reset delivery,MFA,delegated permission APIs,full onboarding/entitlement ledgers,background job/export/download scopes,Android auth or production hardening. No public signup or production admin bootstrap. Only synthetic users are created in tests. See docs/api/M1_IMPLEMENTED_API.md at repository root.

Origin:officiallaravel/laravel13.x skeleton9ffef3bc4241e3cc333ef267258a404d0d95f194;noNurserycodeorcredentials copied. Framework dependencies retain upstream licenses;this project's private business code is proprietary.

Security review: school device tokens are bound to the login school; platform health requires explicit platform authority. Provisioning services validate independently; audit rows reject database UPDATE/DELETE through an additive migration. Run `vendor/bin/phpunit --display-warnings --fail-on-warning` and `composer audit --locked`. See ADR-020 and M1 security evidence before extending this foundation. Browser/activation/reset/MFA remain pending.
