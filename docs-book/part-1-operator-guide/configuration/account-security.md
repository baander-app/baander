# Account security configuration

Configure passkeys, TOTP, passwords and account-verification links here. OAuth keys and token lifetimes are in [Authentication](authentication-and-limits.md). Account support procedures are in [Account recovery](../administration/account-recovery.md).

## Passkeys (WebAuthn)

Configured in `config/packages/auth.yaml`:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `auth.passkey.timeout` | `300000` (5 min) | WebAuthn ceremony timeout in milliseconds. |
| `auth.passkey.authenticator_attachment` | `platform` | Prefer platform authenticators (Touch ID, Windows Hello). |
| `auth.passkey.user_verification` | `preferred` | Whether to require user verification during authentication. Whatever the setting, an account with TOTP enabled accepts a passkey sign-in only with user verification, such as a PIN or fingerprint on the authenticator. |
| `auth.passkey.resident_key` | `preferred` | Whether credentials should be discoverable (passkeys). |
| `auth.passkey.attestation` | `none` | Attestation conveyance preference. |

Passkey challenges are stored only in Redis, and each works once. While Redis is
unavailable, passkey registration and sign-in answer `503` with `Retry-After`; password
sign-in is unaffected by the challenge store. Adding a passkey needs a verified email
address and the user's current password, plus a TOTP code when TOTP is on.

## TOTP

| Parameter | Default | Description |
|-----------|---------|-------------|
| `auth.totp.window` | `1` | Number of 30-second steps accepted on either side of the current one, for clock skew; `0` accepts the current step only. Set in `config/packages/auth.yaml`. |
| `auth.totp.issuer` | the application name | Issuer name the user's authenticator app shows. |

Each code works once: after a code signs the user in or confirms a change, the same code is refused, so a user who needs two codes in a row waits for the next one. Baander stores TOTP secrets encrypted under `AUTH_TOTP_ENCRYPTION_KEY` (see [OAuth 2.0](authentication-and-limits.md#oauth-20)). Turning TOTP on needs a verified email address and the current password.

A user who loses their authenticator, or whose secret no longer opens under the configured key, gets their TOTP turned off by an operator with [`app:user:reset-totp`](../commands/app-user-reset-totp.md) or the admin API's `POST /api/admin/users/{publicId}/reset-totp`, which also signs them out everywhere.

## Passwords (Argon2id)

`config/packages/security.yaml` sets the Argon2id password hasher's memory cost
to 65536 KiB and its time cost to 4.

## Password reset

| Variable | Default | Description |
|----------|---------|-------------|
| `PASSWORD_RESET_EXPIRE` | `60` | Lifetime of a password reset token, in minutes. Must be at least `1`. |

A user has at most one outstanding reset token; a new request replaces it. The token is removed when it is redeemed, when the account is deleted, and when the account's email address or password changes. Baander stores only a SHA-256 hash of the token.

Self-service reset needs working email. When a user asks for a reset on the web login page, `POST /api/auth/password/reset-request` issues a token for an existing account and emails a link through `MAILER_DSN` (see [Mail](environment-variables.md#mail)). The link opens `APP_URL/reset-password`, so `APP_URL` must be the address users reach the web app at. The token sits in the link's fragment (`#token=…`); browsers do not send fragments to the server, so the token stays out of access logs and `Referer` headers. The email states the link's lifetime from `PASSWORD_RESET_EXPIRE`.

Baander sends the email after the HTTP response has gone out, so the request takes as long for an unknown address as for a real account. A failed send does not change the response either. Baander logs the failure with the user ID but without the token or the address, and does not retry; the user can ask for a new link. The email is written in the user's [email language](server-settings.md#email-language), which Baander looks up when it sends the email, after the response.

Without working email, reset a forgotten password with [`app:user:reset-password`](../commands/app-user-reset-password.md) or from the admin panel.

A password change revokes the sessions that the old password started. Redeeming a reset token, an administrator reset and `app:user:reset-password` revoke all of the user's access and refresh tokens. When users change their own password, the session that made the change stays signed in and their other sessions are revoked, including one that signed in while the change was being saved.

A reset link also settles the account's email address, because opening it proves the user reads that mailbox. If the address was verified, a pending email change is dropped. If it was never verified, someone else may have registered the account under that address, so the reset verifies the address and removes the account's TOTP and passkeys along with its sessions.

## Email verification

| Parameter | Default | Description |
|-----------|---------|-------------|
| `auth.email_verification_token.ttl` | `86400` (24 hours) | Lifetime of an email verification token, in seconds. Set in `config/packages/auth.yaml`; must be at least `60`. |

Verification confirms that the user owns the account's email address. Until it does, Baander sends the account no notification email. Operator-created accounts start verified; every other account needs working email to verify (see [Mail](environment-variables.md#mail)).

### Registration and address changes

Baander emails a verification link when a user registers or changes their own address in **Settings**. Registration answers the same way whether or not the address already has an account; for a taken address Baander creates nothing and instead emails the account's owner that someone tried to sign up with it, with a link to reset the password.

A user's change needs their current password, plus a TOTP code when TOTP is on, and stays pending until they open the link sent to the new address; until then the account keeps its old address. The request gets the same answer whether or not another account uses the new address. When the change takes effect, Baander emails the old address that the account's address changed; an administrator's change sends the same notice.

An address an administrator sets in the admin panel or with [`app:user:change-email`](../commands/app-user-change-email.md) takes effect at once and counts as verified, and [`app:user:mark-email-verified`](../commands/app-user-mark-email-verified.md) verifies a user's current address without email. Adding a passkey or turning on TOTP needs a verified address. The link opens `APP_URL/verify-email`, so `APP_URL` must be the address users reach the web app at. As with password reset, the token sits in the link's fragment (`#token=…`) and stays out of access logs and `Referer` headers.

A signed-in user whose address is unverified can ask for a new link in **Settings**, which calls `POST /api/auth/me/email/verification`. That endpoint answers the same way whether or not it sent an email.

### Token replacement and email delivery

A user has at most one outstanding verification token; a new link replaces it. A token verifies only the address it was sent to. It is removed when it is redeemed, when the account is deleted, and when the account's email address changes, so a link sent to an old address stops working. Baander stores only a SHA-256 hash of the token, with the address.

Baander sends the email after the HTTP response has gone out, or at once when the change comes from the command line. A failed send never fails the registration or email change that caused it. Baander logs the failure with the user ID but without the token or the address, and does not retry; the user can ask for a new link. Like the reset email, the verification email is written in the user's [email language](server-settings.md#email-language).

## Token binding

Every access token is bound to the DPoP key that requested it, and every authorization code to the DPoP key its pushed request named; see [OAuth clients and cross-origin access](../administration/sessions-and-access.md#oauth-clients-and-cross-origin-access). A token can also be bound to a client fingerprint; no token is bound to IP addresses. When the request that issued a token sent the `X-Baander-Client-Fingerprint` header, every later request with that token must send the same value, or Baander rejects the token. A refreshed token keeps the fingerprint of the token it replaces.

A token issued without the header is not fingerprint-bound and ignores it. No setting configures these checks.
