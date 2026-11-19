# Security & Operations Hardening — 0.9.8

The release provides a read-only security posture assessment; it **does not** grant permissions, rotate encryption keys, enroll administrators, repair roles, or change database state.

## Operator audit

Run from the release directory using the same environment and protected credentials as the application:

```bash
php bin/cajeer security:audit
php bin/cajeer security:audit --tls
php bin/cajeer operations:check
php bin/cajeer tls:check
```

`security:audit` returns exit code **0** only if the production configuration and live PostgreSQL identity state pass the checks, **2** for a failed security policy, invalid role/TOTP state, or unavailable database, and **1** for CLI/bootstrap failure. The JSON contains only control names, booleans and aggregate counts, never user IDs, emails, encryption material, or exception text. `--tls` additionally probes the public `APP_URL`, verifies the certificate chain and name through the system CA, checks the negotiated TLS version and treats expiring certificates as a failure; ensure outbound access to the gateway. Without `--tls`, live certificate validity is not tested.

Required production controls: HTTPS `APP_URL`, disabled debug, mandatory administrator TOTP, valid configured TOTP encryption key, nonzero idle session limit, nonzero recent-reauthentication interval, authenticated metrics if enabled, and verified PostgreSQL TLS for remote database hosts. Local socket and loopback PostgreSQL connections are exempt from the remote TLS requirement. Operators must set `SECURITY_SESSION_IDLE_SECONDS=1800`, `SECURITY_REAUTH_SECONDS=300`, `SECURITY_2FA_REQUIRED_FOR_ADMINS=true`, and provide the protected secrets as documented in [Security Operations](Security-Operations-Stable.md). After changing session policy, verify current sessions using your normal authentication procedure.

The PostgreSQL audit checks unenrolled active administrators, inconsistent encrypted TOTP enrollment records, global/project RBAC assignment scope, and custom role permissions against the enforced built-in ceilings. The CLI also decrypts every stored TOTP secret with the configured key material in bounded pages, and fails on corrupted ciphertext or an incomplete active-key rotation. Ciphertext and decrypted plaintext are never included in the output. A missing or inaccessible database **never** counts as a successful audit. Investigate invalid role rows, audit logs and conflicting concurrent admin changes before attempting to repair. No automatic deletion or grant rewrite is performed. Role permission checks are paginated in 250-row batches with an explicit 25,000-role upper bound; exceeding that bound fails closed instead of exhausting PHP memory or causing unbounded scrape load.

## Metrics and alerting

Use the existing protected `/metrics` scrape. New gauges are `cajeerlogs_security_configuration_healthy`, `cajeerlogs_security_identity_audit_available`, `cajeerlogs_security_admins_without_totp`, `cajeerlogs_security_totp_state_mismatch`, `cajeerlogs_security_orphan_totp`, `cajeerlogs_security_global_role_scope_mismatch`, `cajeerlogs_security_project_role_scope_mismatch`, and `cajeerlogs_security_invalid_role_definitions`. Aggregations contain no PII. When PostgreSQL is unavailable, only `identity_audit_available=0` is emitted; absent counts do **not** mean zero. The supplied alerts cover unsafe configuration, audit unavailability, invalid authorization/TOTP states and unenrolled administrators. Check and reload Prometheus/Alertmanager using the procedures in `deploy/monitoring`.

The metrics are an alarm signal, not a replacement for periodic interactive `security:audit --tls` and disaster recovery drills. Do not expose `/metrics` publicly or store the Prometheus bearer token in an accessible web directory.

## Incident response

1. For `SecurityConfigurationUnsafe`, compare effective `.env` with the production profile using `security:audit`; correct the unsafe control and restart the application. Readiness deliberately stays down while production controls are unsafe.
2. For `SecurityAuditUnavailable`, first restore PostgreSQL access and verify the installed schema. Do not interpret absent security counts as healthy.
3. For `InsecureIdentityState`, investigate the offending role/TOTP records with a privileged operator and check the audit chain (`audit:verify`). Preserve evidence before making changes.
4. For `AdministratorWithout2FA`, require the administrator to complete TOTP enrollment. Blocking the account temporarily is preferable to bypassing the policy. Do not reset TOTP unless the identity is independently verified.
5. For certificate warnings, verify using `tls:check` from the deployment network, renew the certificate and confirm trusted CA and hostname; never disable verification.

## Acceptance and limitations

Local tests use deterministic PDO fixtures to exercise both successful and failed states. Production acceptance also requires the PostgreSQL 17/18 integration matrix, actual TLS endpoint, Prometheus rule evaluation and successful supply-chain verification. This version does not claim that an unauthenticated external security assessment or third-party penetration test has been completed.
