# CajeerLogs 1.1.0 — Integrated Production Release

All three integrations are optional or operator-controlled. The default installation remains local-password based, AI disabled, external authentication disabled, unattended update installation disabled. Existing API v1, PostgreSQL 17/18 and PHP 8.4/8.5 remain supported. No new schema migration is necessary: keep all 36 migration files byte-identical, including 0034–0036. Existing 1.0.9 data and backup history must survive a safe upgrade. Do not run migrations from an unverified distribution.

## Operator acceptance before enabling integrations

Run under the intended application account, with private configuration (never add secrets to a source ZIP):

```sh
php bin/cajeer version
php bin/cajeer health:ready
php bin/cajeer migrate:verify
php bin/cajeer operations:integrations
php bin/cajeer operations:integrations --live
```

`operations:integrations` is configuration-only. `--live` contacts OpenAI (a small billed synthetic response, `store=false`, no logs or user data), public SkiF4er ID discovery and GitHub Releases. A nonzero exit means at least one enabled check failed. Disabled means not checked. The command never installs, downloads, logs in on behalf of users, sends a client secret to discovery, or prints upstream output. It deliberately reports `ga_certified: false` and does not prove client registration, actual interactive login, signed artifact installation or release certification.

### ChatGPT

Follow [ChatGPT Assistant](ChatGPT-Assistant-1.0.6.md), [investigation](AI-Log-Investigation-1.0.7.md), and [incidents](AI-Incident-Intelligence-1.0.8.md). Set `AI_ASSISTANT_ENABLED=true`, `OPENAI_API_KEY` and an API model available to your project. Leave `AI_ASSISTANT_INCLUDE_MESSAGES=false` unless the operator has explicitly authorized disclosure of log text. Masking is not a guarantee that arbitrary sensitive content is detected. Test a synthetic project with a real error and a correlated trace, confirm local evidence links and saved incident history, then revoke the test user's project grant and confirm the chat/history are inaccessible. Verify that provider outage does not break ordinary ingestion or login.

### SkiF4er ID

Follow [authentication](SkiF4er-ID-Authentication-1.0.4.md) and [linking/security](Identity-Linking-Security-1.0.5.md). Register a confidential web client with exact HTTPS `APP_URL` + API prefix + `/auth/skif4er/callback`; supply its client ID and secret. Configure the pinned issuer `https://id.skif4er.ru/realms/skif4er`. Confirm discovery and then perform an actual browser login, local TOTP/recovery challenge where enabled, explicit linking with current local credentials, and unlink/revocation. Check a viewer can access only their assigned projects and cannot access the system update API. A revoked external session must not return an in-flight assistant answer or save a new investigation. Keep a separate working local administrator login. Never automatically link by email or grant provider roles.

### GitHub updates

Follow [verified downloads](Verified-Update-Downloads-1.0.2.md), [safe installation](Safe-Update-Installation-1.0.3.md) and [automation](Automatic-Update-Management-1.0.9.md). Start with check mode. Stable downloads now require both the published ZIP attestation and the separately signed `database-certification.json` for the exact ZIP digest, tag, commit and workflow. Incomplete GA evidence blocks download and re-verification; preview assets remain download-only. If an earlier cached stable download lacks its certificate, stop the timer, remove/quarantine only that unverified version directory under operator control and download again. Verify the published release ZIP and sidecar with the project's GitHub attestation; verify backup and a genuinely empty target DB before installation. Rehearse on the actual Linux/systemd host with every ingress, writer and timer in the maintenance inventory. Verify configuration, identity bindings, session provenance, logs, incident history, queue and audit after the update. Exercise pre-commit rollback and post-commit recovery, never revert a database that has accepted new writes. Only then enable stable unattended installation. Docker remains operator-deployed. An unpublished or unsigned artifact must never be auto-installed.

## Release and evidence

Apply this source patch to 1.0.9 and commit all changed/new files. Confirm a real 1.0.9 commit is reachable from main (not a synthetic version substitution). Request release via `release-request/v1.1.0` at the exact main commit or an authorized `v1.1.0` tag. Do not reuse or move a previously published tag. `.github/workflows/runtime.yml` verifies the same archive across the existing PHP/PostgreSQL matrix, exact historical upgrades/rollback (now including 1.0.9), admin/browser acceptance, integration stability, SDK, security, ClickHouse, real Docker smoke and full long-duration async performance gates. The new integrated database stage uses actual local PostgreSQL and session controllers; its OIDC callback and OpenAI response are controlled provider boundaries, not live credentials. The admin certification also executes the integration diagnostics and safe/automatic update regression tests from the verified archive. Missing or failing stages block final certification. `publish-ga` requires the signed archive and certificate, and is the only normal public release path. No live provider login or signed systemd upgrade is inferred from CI fixtures. Record the real provider and host acceptance results before enabling those integrations in production.
