# SkiF4er ID Authentication (1.0.4)

This is a server-side OpenID Connect implementation, using the issuer already configured in NeverLauncher (`https://id.skif4er.ru/realms/skif4er`). The SDK page `https://id.skif4er.ru/sdk/` was unavailable when implementing this release: no proprietary SDK API is assumed. The actual authorization, token, JWKS and UserInfo endpoints are discovered at runtime, constrained to that realm and HTTPS host. Live SkiF4er ID interoperability requires an operator-registered web client and acceptance test.

## Deployment

1. Back up the database. Apply the normal migration procedure (`php bin/cajeer migrate`, or the documented safe update process). Migration 0034 stores external identity bindings and short-lived login attempts. Do not edit earlier migration files.
2. Register a confidential web client in the SkiF4er ID realm with Authorization Code, PKCE S256, RS256 ID tokens, `client_secret_basic`, and scopes `openid profile email`. Require a verified email claim. Configure the exact redirect URL `https://YOUR-CAJEERLOGS-HOST/api/v1/auth/skif4er/callback` (substitute the actual configured API prefix). No wildcard redirects, implicit grant, or password grant. Configure TLS and correct trusted proxy settings.
3. Obtain the stable `sub` claim for the intended account through a trusted provider administration channel (not email, username, an unverified token, or a browser-supplied claim). As a trusted CajeerLogs operator with the CLI/database permissions, bind it to an existing local account:

   ```sh
   php bin/cajeer auth:skif4er-bind operator@example.org VERIFIED_SUBJECT --confirm
   ```

   This command does not create users, elevate roles, or silently change an existing binding. Keep the local password and recovery methods. Account binding and unbinding by users is planned for a later release.
4. Set `SKIF4ER_ID_ENABLED=true`, `SKIF4ER_ID_CLIENT_ID` and `SKIF4ER_ID_CLIENT_SECRET` in private configuration. On native installations `SKIF4ER_ID_CLIENT_SECRET_FILE` may instead point to a private absolute file. Do not put client secrets in a browser or source control. Use an HTTPS `APP_URL`. Restart application services; the button is shown only on HTTPS when enabled.
5. Open `/admin/`, select **Войти через SkiF4er ID**, complete the provider login, then confirm the local login and local TOTP/recovery code if enrolled. A normal local session is created and works with existing permissions and logout. Local password reauthentication is still required for privileged actions when configured. No provider tokens are stored or returned to the browser.
6. Acceptance: verify the intended account and permissions, unknown subject rejection, blocked account denial, wrong TOTP rejection, provider cancellation, replayed callback denial and local password login. Do not enable in production until these live checks pass. If provider is unavailable, use local login; disable the feature by setting `SKIF4ER_ID_ENABLED=false` and restarting.

The flow cookie is Secure, HttpOnly, host-only and SameSite=Lax; local sessions retain SameSite=Strict. State and cookie are independently random, state is consumed before token exchange; completion is bound to a rotated cookie and one-use database record, with 5-minute expiry and bounded OTP retries. The existing auth cleanup removes expired attempts. Restrict proxy access logs to URL paths, never callback query strings; the bundled production gateway disables access logging. Do not record Authorization or Cookie headers. Provider logout, refresh, session revocation propagation and self-service linking are not implemented in this version; local session revocation and logout remain available.
