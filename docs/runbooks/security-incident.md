# Credentials, MFA, TLS and audit compromise

## Trigger

`production_admin_totp`, `production_https`, `production_metrics_protected`, `production_recent_authentication`, or `audit_integrity` fails; suspicious access, leaked API token, stolen keys or authentication bypass is suspected.

## Diagnosis

Preserve a restricted evidence snapshot and involve security operations; don't echo passwords, tokens or full audit payloads into incident chat. Validate TLS and audit integrity from the authorized host.

```sh
php bin/cajeer operations:incident
php bin/cajeer tls:check
php bin/cajeer audit:verify
php bin/cajeer auth:totp-key-verify
```

## Intervention

Restrict access using the existing ingress/network/IAM controls under security incident authority. Revoke exposed API tokens and active sessions using the application’s authorized administration flows. Do not reset MFA for users without verified identity and explicit approval; rotating TOTP encryption keys without preserved old key material can make recovery impossible. Keep independent copies of signed evidence. Follow [security operations](../Security-Operations.md).

## Verification

Verify HTTPS chain/name, fresh authentication and MFA enrollment, audit chain continuity, no unauthorized access, and readiness. Document exactly which credentials/sessions were revoked.

```sh
php bin/cajeer tls:check
php bin/cajeer auth:totp-key-verify
php bin/cajeer audit:verify
php bin/cajeer operations:triage
```

## Escalation

Notify security incident response immediately for leaked crypto keys, unexpected audit-chain breaks, suspected tampering or confirmed unauthorized access; follow regulatory and customer notification procedures applicable to your deployment.
