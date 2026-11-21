# F007 school registration intake

The first backend slice accepts an application and provides token-scoped status. Submit with a synthetic organization and contact until public intake is approved and privacy notice, retention, and operational procedures are in place.

## Implemented API

| Method and path | Input | Result |
| --- | --- | --- |
| POST /api/v1/registrations | JSON object containing organization_name, category, contact_name, contact and notice_version | 201 with the status fields and a one-time tracking_token in data |
| GET /api/v1/registrations/status | X-Registration-Token request header | 200 with data.id, data.state, data.support_contact and data.demo_available |

Applications start in submitted. The submitted contact is an unverified email address or international phone number with its country code. The notice version must match the configured version. The status response contains no name, contact, or reusable token. Keep the token confidential and exclude it from request logs and telemetry. The header keeps it out of URLs. A missing, malformed, or unknown token returns 404. Intake and status responses use no-store.

The database stores an SHA-256 digest of the random 256-bit token in registration_requests. A unique digest of normalized organization name, category, and contact rejects duplicate submissions even if two requests arrive concurrently. A duplicate returns a generic 409 and issues no token. Limits are five submission attempts per ten minutes and 30 status lookups per minute, each per client IP. JSON requests over 4 KiB are rejected. Direct HTTP requests are denied. HTTPS termination must use the existing trusted proxy boundary.

## Configuration and remaining work

EDU_REGISTRATION_ENABLED defaults to false. When explicitly enabled, a valid EDU_REGISTRATION_NOTICE_VERSION is required. EDU_REGISTRATION_SUPPORT_CONTACT supplies public support text and can be empty in tests. Do not configure this slice for public use before the notice content, retention and operational controls are reviewed.

This slice creates neither a school nor an account. Contact verification, token recovery, Super Admin review and decision history, direct school creation, and the public form and support links are subsequent work. Demo availability stays false until the separate provisional access workflow exists. The broader OpenAPI design includes later features. This document records the implemented subset, including the header-based token and 201 submission response.

## Acceptance

RegistrationIntakeTest covers the disabled default, HTTPS, private status, token hashing, stale notices, disallowed fields, duplicate normalization and rate limiting. Verify on disposable MariaDB and run the full CI before review.
