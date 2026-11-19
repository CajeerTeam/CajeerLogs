# CajeerLogs 0.8.3 — Security Review & Hardening

This is a targeted source-code security review with executed regression tests. It is **not** an independent penetration test and does not certify third-party dependencies or production configuration.

## Confirmed findings and changes

1. **HMAC signing policy and key expiry:** PostgreSQL `boolean` values can be surfaced by different PDO configurations as native booleans or text (`t`/`f`). A strict comparison against `true` could omit mandatory signing when the project policy was represented by `t`. A truthy `f` could also keep an expired previous signing key valid or allow changes to an inactive token. `PostgresBoolean::required()` accepts only supported representations and fails closed on unexpected data. `IngestSigning` now uses it for project policy, old-key expiry, token activity, and signing metadata. Regressions cover both representations and invalid values.
2. **TOTP enrollment and login:** A string `f` was truthy in PHP, potentially preventing enrollment-required checks or treating unenrolled accounts as if 2FA were enabled. `UserRepository::findByEmail`, session authentication, and public user projection now canonicalize the database field before use. The behavior is verified with native and textual values.
3. **Installer privilege boundaries:** Strict native-boolean checks in first-administrator verification and role/schema preparation could misread PostgreSQL `t`/`f` results, allowing an existing administrator or elevated role to be missed in certain driver configurations. Checks are canonicalized and unknown types fail closed. The installer still requires separate migration and runtime roles.
4. **Restore quarantine, migrations and integrity checks:** A PostgreSQL text `t` could be misread as a false restore marker, making a database with an unfinished restore appear available. The same strict comparison could skip integrity audit checks, reject legitimate advisory locks or misreport schema/sequence privileges and worker leases. RestoreFence, MigrationRunner, MigrationGuard, ConnectionFactory, DatabaseStatus, IntegrityInspector, queue and installer status now normalize booleans and fail closed when the database returns an unexpected value. The restore-quarantine regression uses a PDO-compatible test double returning both native and PostgreSQL text booleans.
5. **HTTP message framing:** The front controller previously rejected simultaneous `Content-Length` and `Transfer-Encoding` only on ingestion endpoints. All routes now reject this combination before reading the body. This is defense-in-depth; the reverse proxy must also reject ambiguous framing.
6. **Free-text secret masking:** The existing redactor handled structured camelCase context keys, but did not recognize common camelCase credentials in free text. It now masks `accessToken`, `refreshToken`, `apiKey`, `idToken`, `clientSecret`, `privateKey`, `proxyAuthorization`, and `setCookie` assignments. This is best effort, **not** a promise that all secret formats can be recognized.

## Executable checks

Run from the release directory:

```bash
php tests/security_hardening.php
php tests/hmac.php
php tests/identity.php
php tests/secure_installer.php
php tests/deployment_security.php
php tests/webhooks.php
php tests/security_operations.php
python3 tests/deployment_tls.py
python3 tests/release.py
```

`tests/security_hardening.php` runs in CI alongside `composer audit` and is included in the mandatory browser/admin release certification stages (using the verified release ZIP). Neither static results nor simulated HTTP endpoints substitute for real PostgreSQL and public HTTPS E2E checks.

## Follow-up operational review

- Verify that TLS is terminated by the intended trusted proxy and the backend isn't publicly exposed.
- Use dedicated least-privilege PostgreSQL migration and application roles; review custom role memberships and schema grants.
- Run external S3 and ClickHouse tests with real credentials, strict TLS verification, and isolated test projects.
- Review dependency advisories and secrets in the actual release environment. Dependency scanning is performed in CI; this document does not claim it has passed at a third-party provider.
- Confirm that HMAC secrets, TOTP encryption keys, and backup encryption keys are injected through private secrets files with documented rotation/recovery procedures.
- Run PostgreSQL E2E and browser security flows before changing the release status from `public-preview` to Stable.

**Versioning:** API v1 remains unchanged. PostgreSQL and ClickHouse schemas have no new migrations in 0.8.3. Older backups remain supported under the existing restore compatibility policy.
