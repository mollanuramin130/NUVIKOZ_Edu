# Security baseline

Deny by default. Authenticate identity,select/validate membership,tenant-first load,resource/guardian/assignment policy,stage/access/entitlement,feature cap,state/version,then transactional command. Recheck in jobs,exports and downloads. No self-escalation through editable role/tenant fields.

Exactly six initial roles. SA provisions school logins;Principal can enter admission profiles but cannot provision/activate logins. Teacher notices require Principal OR SA review;after16:00school-local attendance correction needs SA approval. Student homework upload disabled in all phases. Demo all user files and tenant-generated physical files denied;shared samples are read-only.

Secure password hashes,random one-time activation secrets,throttling,revocation,safe recovery and privileged MFA are planned implementation requirements. No real PII/keys/bank secrets in Git or AI handoff. Private files authorize tenant and current link at every download. Snapshot/support access is exceptional/time-bound/audited. Retention/legal basis for minors and offsite key custody remain production gates.
