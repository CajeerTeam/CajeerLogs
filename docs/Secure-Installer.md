# Secure Installer — 0.4.7

CLI-only installation and upgrade. The public web installer remains disabled. Run commands as the installation operator; the PHP runtime must be able to read the configuration and runtime secret files. Use a dedicated PostgreSQL runtime role and a separate migration role (see [Installer-Administrator.md](Installer-Administrator.md)).

## Preflight and installation

Configure the HTTPS APP_URL for production, database host/database/schema, runtime role and credentials. Prepare a private configuration directory owned by the operator (not group/world writable), a writable storage directory, and PostgreSQL roles. For a new dedicated schema, use `php bin/cajeer install:prepare-schema --confirm` as described in the administrator guide. Schema preparation is a separate mutating operation, not part of dry-run.

```sh
php bin/cajeer install --dry-run
php bin/cajeer install:check
php bin/cajeer install --non-interactive
php bin/cajeer install:status
php bin/cajeer migrate:verify
php bin/cajeer db:verify
php bin/cajeer doctor
```

Both checks report JSON and return nonzero on failed checks. They inspect configuration paths, disk space, runtime extensions, database connection/privileges and migration history without writing configuration, applying migrations or creating an administrator. An empty prepared schema can pass preflight; this does not mean the application is installed. Use `install:status` after installation. An existing config file must be regular, private (0600/0400), owned by the operator and not linked. The configuration directory must be canonical and operator-controlled. Fix ownership/permissions before starting; do not run the installer as root merely to bypass access restrictions.

## File-backed credentials

An explicit runtime allowlist supports a `_FILE` suffix: `DB_PASSWORD`, `SECURITY_HMAC_SECRET`, `SECURITY_TOKEN_PEPPER`, `SECURITY_PRIVACY_HASH_PEPPER`, `SECURITY_TOTP_KEY`, `SECURITY_TOTP_ACTIVE_KEY`, `SECURITY_TOTP_PREVIOUS_KEY`, `WEBHOOK_SIGNING_SECRET`, `PROMETHEUS_TOKEN`. Supply an absolute canonical path outside `public/`, e.g.:

```dotenv
DB_PASSWORD_FILE=/etc/cajeerlogs/secrets/db-password
SECURITY_TOTP_KEY_FILE=/etc/cajeerlogs/secrets/totp-key
```

Files must be regular, single-link, owned by the effective process user (where POSIX is available), mode 0400 or 0600, and at most 4096 bytes. A single terminal LF/CRLF is removed. A file and a real inline value for the same setting are mutually exclusive; remove old inline values before switching. Example placeholders beginning `change_me` do not count as real credentials. The installer persists runtime references, not their contents. Mount the files into every PHP/worker container at the same path with appropriate ownership and permissions; the supplied Compose configuration does not automatically create or mount arbitrary secret files. Keep the actual files backed up separately and never place them in the document root.

`DB_MIGRATION_PASSWORD_FILE` and `CAJEER_ADMIN_PASSWORD_FILE` are operator-only credentials; neither is persisted. Supply the migration role and admin email through the existing environment options, and remove elevated credentials from the runtime environment after installation. Administrator password files follow their existing 1026-byte limit. Do not put passwords in CLI arguments. Use an external secret delivery mechanism or protected environment input.

## Upgrade from 0.4.6

Stop API and workers, create a backup with the old release (`php bin/cajeer backup:create`), preserve all configuration and keys, then overlay the 0.4.7 files. Before migration or restart:

```sh
php bin/cajeer upgrade:check BACKUP_ID
php bin/cajeer migrate:verify
php bin/cajeer db:verify
php bin/cajeer doctor
```

The upgrade check verifies the backup manifest, SHA-256 and PostgreSQL archive TOC, supported version/schema and matching pending migration history. It does not perform a restore or prove that the backup contains the latest production data. A restore rehearsal remains necessary. 0.4.7 introduces no schema migration. Do not delete existing secret keys or rotate them as part of installation. Repeat an interrupted installation with the same configuration and credentials; the installer verifies migration history and existing administrator, checks TOTP/HMAC decryption, and refuses incompatible credential changes. It does not silently replace existing secrets or perform destructive rollback. If verification fails, keep the instance stopped, recover the original configuration/keys or restore a verified backup to an isolated database using the documented restore fence.
