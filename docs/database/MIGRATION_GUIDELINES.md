# Database migration guidelines

Use a dedicated Edu MySQL-compatible MariaDB database;never Nursery credentials/schema. Dictionary is a logical contract,not executed SQL. Test migrations and composite foreign keys on MariaDB,not SQLite alone.

School tables:non-null school_id,unique(school_id,id),composite tenant references,query indexes matching school/session/status filters. Immutable internal IDs;display identifiers versioned/tenant-unique. Historical academic/financial records use restrictive references,not cascading deletion.

Expand schema first;bounded checkpointed backfill;verify tenant counts/constraints;switch compatible readers/writers;contract only in a later approved release. No irreversible down-migration as routine rollback. Restore drills use synthetic isolated data;production migrations/restores require separate authorization. Store metadata/private paths,not PDF binary blobs in DB.

Quota locks:global budget,school counter,effective policy,daily counter,document. Commit rechecks stage/policy/date and reservations;retained physical versions count bytes until actual approved purge. M1 establishes these invariants without implementing file features yet.
