# HTTPS & Deployment Security — 0.4.6

## Upgrade and direct TLS

No new migrations; 0001–0020 remain unchanged. Set `APP_URL` to the exact external HTTPS origin (including a nonstandard external port); no path, query or user info. Rebuild app and gateway together. The gateway entrypoint generates its exact Host allowlist from APP_URL before starting Nginx. Invalid/mismatched Host returns 400, including static assets. The application independently checks Host before reading the body. Production non-HTTPS dynamic requests are rejected with 403, except GET/HEAD runtime/readiness probes (`/`, `/health`, `/ready`, `/api/v1/health`). Those probes disclose only minimal status; they do not grant access to protected data. No HTTP listener or automatic redirect is enabled in the shipped Compose stack. Use a dedicated external redirect listener only if needed; redirect to a fixed configured HTTPS origin, never a client Host.

```dotenv
APP_URL=https://logs.example.com
SECURITY_TRUSTED_PROXIES=
CAJEER_TLS_DIR=/etc/cajeerlogs/tls
CAJEER_HTTPS_BIND=0.0.0.0
CAJEER_HTTPS_PORT=443
```

For non-Docker use of the supplied Nginx configuration, run `APP_URL=https://logs.example.com sh deploy/production/gateway-origin.sh` as the Nginx configuration operator before `nginx -t` (it writes `/etc/nginx/conf.d/00-origin.conf`). The generated map is required.

Compose mounts `fullchain.pem` and `privkey.pem` read-only in the gateway. Restrict private key permissions to the gateway operator. Nginx TLS 1.2/1.3, session tickets disabled; HSTS one year, no includeSubDomains/preload. HTTP error and static responses also have nosniff, DENY framing, no-referrer and restricted Permissions-Policy. The PHP admin page retains its strict CSP. Do not publish PHP-FPM or PostgreSQL ports. Nginx per-peer request/connection limits remain unchanged. External proxy/CDN should also enforce per-client admission.

## Behind a trusted reverse proxy

Default: ignore all forwarded headers, use the transport IP and actual TLS flag. Only explicitly listed immediate proxy addresses/CIDRs can override those two values. Both `X-Forwarded-For` (one literal IPv4/IPv6 address) and `X-Forwarded-Proto` (`https` or `http`) must be present and valid. Chains, ports, malformed and ambiguous values fail 400. `Forwarded`, `X-Forwarded-Host`, `X-Real-IP` and other forwarded fields do not establish trust. IPv4-mapped IPv6 peer addresses normalize to IPv4. Universal CIDRs /0 are forbidden; use exact observed peer addresses, not a public or shared container network. If the proxy terminates TLS, its reported scheme takes precedence over the TLS connection to the backend. The external Host must be preserved and match APP_URL.

Example for a separately deployed final Nginx proxy (replace names, IPs and upstream with real deployment values):

```nginx
location / {
    proxy_set_header Host logs.example.com;
    proxy_set_header X-Forwarded-For $remote_addr;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Forwarded "";
    proxy_set_header X-Forwarded-Host "";
    proxy_pass https://cajeer_gateway;
    proxy_ssl_server_name on;
    proxy_ssl_name logs.example.com;
    proxy_ssl_verify on;
    proxy_ssl_trusted_certificate /etc/ssl/certs/ca-certificates.crt;
}
```

`SECURITY_TRUSTED_PROXIES=192.0.2.10/32,2001:db8::10/128` illustrates the syntax only. These must be the actual transport peer addresses observed by PHP, not client headers. Firewall the backend so untrusted clients cannot impersonate those addresses. Never use `$proxy_add_x_forwarded_for` with this policy: the last hop must **overwrite** headers, not append client values. If multiple proxies exist, resolve client IP safely at the trusted edge, then pass a single verified client IP from the last hop. The shipped gateway itself continues limiting its direct peer, while the application uses the resolved client for login and API limits. For direct FastCGI deployments use equivalent trusted network boundaries and set REMOTE_ADDR/HTTPS only from the webserver.

## Certificate check and renewal

From an operator host that resolves and can reach APP_URL, run:

```sh
php bin/cajeer tls:check
# Private CA (explicit trust) and warning threshold in days:
php bin/cajeer tls:check /etc/cajeerlogs/ca.pem 14
```

The command performs a real verified TLS handshake (TLS 1.2/1.3), validates certificate chain and public name, reports expiration, protocol and SHA-256 fingerprint. Exit 0 only if valid and beyond warning threshold; exit 1 for expiring or failed handshake. No certificate bypass or credentials are accepted. It does not automatically issue certificates or change the key. Use your external ACME/PKI renewal process. Mount a directory, not individual files; publish matching certificate/key together, then run `docker compose --env-file .env.production -f compose.production.yaml exec -T gateway nginx -t` and `docker compose --env-file .env.production -f compose.production.yaml exec -T gateway nginx -s reload`. If validation fails, restore the previous matching pair before retrying. Reload is graceful; verify actual served certificate via `tls:check`. For Compose local IP tests use the appropriate CA and IP SAN.

## PostgreSQL TLS

The shipped private Compose database remains on an isolated internal network (DB_SSLMODE=disable). For an external PostgreSQL service configure `DB_REQUIRE_TLS=true`, `DB_SSLMODE=verify-full`, `DB_SSLROOTCERT=/etc/cajeerlogs/postgres-ca.pem`, and a DB_HOST matching its certificate SAN. The bootstrap rejects missing CA or downgrade to weaker sslmode; libpq verifies the server certificate and host at connection time. Configure the same CA and settings for workers, migration CLI and backup CLI; pg_dump/pg_restore inherit DB SSL settings. Mount the CA read-only and use a deployment override for external DB rather than editing secrets into the image. The runtime account must not have public network exposure. No database TLS connection is established by the web diagnostics merely to probe a certificate: normal database readiness tests the configured connection.

## Verification

`php tests/deployment_security.php`, `python tests/deployment_gateway.py` (requires local Nginx), `python tests/deployment_tls.py`, `python tests/http_runtime.py`, `php bin/cajeer openapi:lint`, `npm --prefix admin run build`. Production smoke validates exact Host, static headers, forged forwarding and TLS against actual Docker gateway. The complete release gate includes the exact ZIP, DB matrix, browser E2E and upgrade from 0.4.5. System report exposes configuration status and count of trusted proxy networks, not their addresses or certificate/private key data. Release remains public-preview until all required gates pass.
