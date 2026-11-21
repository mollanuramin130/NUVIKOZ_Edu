# M2 — Academic offering boundaries

Status: implemented backend design within approved v1.1; owner acceptance pending.

Keep school-wide classes/subjects distinct from session-specific class offerings and subject offerings. Sections bind to class offering. Derive child session/class rather than accepting redundant independent IDs. Use composite same-school FKs and domain unique keys, restrictive history references and generated immutable IDs. All current commands are create/list; version1 denotes an immutable initial record, not an edit history feature.

Reuse the bounded AcademicCatalog service/browser routes rather than a generic repository or mobile-token alias. Recheck current School Admin/MFA/identity and entitlement after acquiring school→identity→membership locks; parent eligibility and duplicates share that serialization. Matching replay requires current authorization and eligible parents. Listing historical relationships remains authorized after entitlement expiry; suspension/revocation denies access.

Staff profiles/effective-dated assignment scope is not implemented, even though teacher membership identities exist. Keep section teacher reference null and reject all non-null commands. Do not infer current class teacher authorization from mere role membership; a later staff slice owns assignment eligibility/history. No full staff module, enrollments, subject grading rules, automatic session activation or UI changes are introduced.

Demo caps are bounded per-school engineering defaults; quota and uniqueness races are exercised with independent MariaDB processes. Pending MethodChannel device acceptance is a separate mobile security gate. Production issuer/lost-factor/delivery and hosting controls remain unchanged.
