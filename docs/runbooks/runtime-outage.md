# Runtime or gateway unavailable

## Trigger

`/ready` returns 503, `runtime`, `storage_headroom`, `worker_*` or `production_https` fails, or the reverse proxy serves errors.

## Diagnosis

On the application host, verify PHP runtime, local storage, application health and TLS separately. Do not treat `/health` as proof PostgreSQL is ready.

```sh
php bin/cajeer doctor
php bin/cajeer health:ready
php bin/cajeer operations:triage
php bin/cajeer tls:check
```

Inspect Nginx gateway and PHP-FPM service/container logs through the deployment supervisor. Never include `.env`, session cookies or private keys in incident attachments. Check disk/inodes and that `storage/cache` and private backup filesystems are owned by the service account.

## Intervention

If an individual PHP/queue process is unhealthy and PostgreSQL is sound, restart **only** that process through systemd or Compose after documenting impact; do not restart the database to fix a gateway error. If disk is full, move non-authoritative temporary files under an approved policy; never delete unverified backups, `.pruning` directories, active jobs or audit records. For TLS failure, deploy a correctly validated matching certificate/key pair and reload the gateway. See [HTTPS security](../Production-Security.md).

## Verification

Confirm end-to-end TLS, `/ready`, queue health and an authenticated write/read test on a test project. An HTTP 200 from a proxy or `/health` alone is insufficient.

```sh
php bin/cajeer tls:check
php bin/cajeer health:ready
php bin/cajeer operations:check
```

## Escalation

Engage infra owner on persistent 5xx, DB owner when readiness remains down, security owner for key/certificate exposure, and incident commander when customer data is unavailable.
