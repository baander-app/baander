# User Management

How to create user accounts, assign roles, and manage authentication methods.

## Creating Users

Users can self-register through the public `POST /api/auth/register` endpoint, or operators can create accounts directly with the `app:user:create` command. Operator-created users are immediately verified and can log in right away.

Create a regular user:

```bash
make exec cmd="php bin/console app:user:create alice@example.com Alice"
```

Create an admin (the command asks for confirmation before granting admin privileges):

```bash
make exec cmd="php bin/console app:user:create admin@example.com Admin --role admin"
```

### Arguments and options

| Argument / option | Required | Default | Description |
|-------------------|----------|---------|-------------|
| `email` | Yes | — | User's email address |
| `name` | Yes | — | Display name |
| `--password` | No | — | Read password from stdin instead of prompting interactively |
| `--role` | No | `user` | User role: `user` or `admin` |

For scripted or CI usage, pipe the password via stdin with `--password`:

```bash
echo "securepassword" | make exec cmd="php bin/console app:user:create alice@example.com Alice --password"
```

See the [full command reference](commands/app-user-create.md) for exit codes and additional details.

### Password requirements

A password must be 8 to 255 characters. There is no formal password policy beyond this — no complexity rules, no expiration, and no history. Passwords are hashed with Argon2id (memory cost: 65536, time cost: 4), which provides strong protection even if the database is compromised. See the [security guide](security.md#password-security) for more on password hashing.

## Roles

Baander uses three roles:

| Role | Description |
|------|-------------|
| `ROLE_USER` | Standard user — can browse libraries, stream media, create playlists, and manage their own preferences |
| `ROLE_ADMIN` | Administrator — has access to the operational and management API endpoints; some changes there need `ROLE_SUPER_ADMIN` |
| `ROLE_SUPER_ADMIN` | Super administrator — includes `ROLE_ADMIN` and may also make the changes reserved for it, such as creating administrators, changing [server settings](configuration.md#server-settings) and [users' settings](#user-settings), and registering, rotating and revoking [OAuth clients](#oauth-clients) |

Roles are assigned at creation time with the `--role` flag, which grants `ROLE_USER` or `ROLE_ADMIN`. A super administrator can assign roles, `ROLE_SUPER_ADMIN` included, through the admin API (`POST /api/admin/users/{id}/roles`). There is currently no CLI command to change a user's role after creation. To modify roles, you must update the `roles` column directly in the database:

```sql
UPDATE users SET roles = '["ROLE_USER", "ROLE_ADMIN"]' WHERE email = 'alice@example.com';
```

### Who can manage users

Two [server settings](configuration.md#server-settings) decide what an administrator who is not a super administrator may do on the admin **Users** page and the `/api/admin/users` endpoints:

| Setting | Default | While it is on |
|---------|---------|----------------|
| `admin.can_view_users` | `true` | Administrators may list users. While it is off, the list request gets `403` and the page explains why. |
| `admin.can_create_users` | `false` | Administrators may create users, but only with the User role. The setting never lets an administrator create another administrator. While it is off, the page hides **Create User**. |

Baander reads both settings on every request, so a change applies at once. Super administrators may always list and create users, with any role. Every other change to an account, such as editing it, assigning roles, resetting its password, disabling, enabling or deleting it, needs `ROLE_SUPER_ADMIN`. Administrators who are not super administrators therefore get a single **View** action for each user, which opens the user dialog read-only.

The console commands are not affected by these settings: console access carries full authority.

## Passkeys (WebAuthn)

Users register passkeys through their browser using the standard WebAuthn API. This is a user-initiated action — operators do not manage passkeys on behalf of users.

Passkeys are supported in all modern browsers:

| Browser | Supported |
|---------|-----------|
| Chrome 67+ | Yes |
| Firefox 60+ | Yes |
| Safari 13+ | Yes |
| Edge 79+ | Yes |

Users can register multiple passkeys on the same account and use any of them to authenticate. If a passkey is lost or the device is unavailable, users fall back to password authentication.

## TOTP 2FA

Users enable and disable time-based one-time password (TOTP) two-factor authentication through the API. This requires an authenticator app such as Google Authenticator, Authy, or 1Password.

Key points for operators:

- 2FA is entirely user-managed — there is no CLI command to enable or disable it on behalf of a user.
- When a user enables 2FA, they receive a QR code and a set of recovery codes.
- If a user loses access to their authenticator and has exhausted their recovery codes, the operator must intervene by resetting the 2FA secret directly in the database and then providing the new secret to the user out of band.

## Resetting a Password

Set a new password for a user who has forgotten theirs:

```bash
make exec cmd="php bin/console app:user:reset-password alice@baander.app"
```

The command prompts for the password, or reads it from stdin with `--password`. It accepts an email address or UUID and does the same as **Reset password** in the admin panel. Both sign the user out of every session. See [app:user:reset-password](commands/app-user-reset-password.md).

Users who forget their password can reset it themselves once [email is configured](configuration.md#mail):

1. On the login page, the user selects **Forgot password?** and enters their email address. The page shows the same confirmation whether or not an account uses that address.
2. If an account does, Baander emails it a link to the **Choose a new password** page. The link works once and expires after `PASSWORD_RESET_EXPIRE` minutes (60 by default).
3. The user enters the new password twice. It must be 8 to 255 characters. If the link was already used, has expired, or the account is disabled, the page says the link is invalid or has expired and links to the request page.
4. Baander sets the password, signs the user out of every session, and returns to the login page with a confirmation.

By default a user can ask for a link 10 times per 15 minutes; after that the page still confirms but no email is sent. See [Password reset](configuration.md#password-reset) for the limits and how the link is built. API clients use the same two endpoints as the web pages: `POST /api/auth/password/reset-request` and `POST /api/auth/password/reset`.

## Verifying Email Addresses

Baander sends notification email only to verified addresses. Operator-created accounts start verified. Self-registered accounts, and any account whose address changes, start unverified, and Baander emails the address a verification link once [email is configured](configuration.md#mail):

1. The user opens the link, which leads to the web app's **Verify your email** page. The page confirms the address, or says the link is invalid or has expired.
2. The link works once and expires after 24 hours. It verifies only the address it was sent to, so after another email change it no longer works.
3. A signed-in user whose address is unverified sees **Not verified** under the email address in **Settings** and can select **Resend verification email**. A new link replaces the earlier one. By default a user can ask 3 times per hour; after that the page still confirms but no email is sent.

See [Email verification](configuration.md#email-verification) for the lifetime, the limits and how the link is built.

## Changing an Email Address

Users change their own address in **Settings**. Operators change it in the admin panel or with the CLI:

```bash
make exec cmd="php bin/console app:user:change-email alice@baander.app alice.new@baander.app"
```

The command accepts the current email address or the UUID. All three ways make the new address unverified, email it a verification link, and end the verification and password reset links sent to the old address. See [app:user:change-email](commands/app-user-change-email.md).

## User Settings

Each user has settings of their own. Currently there is one, `language`, the language Baander emails the user in; it follows the server default until the user or an administrator chooses a language. [Email language](configuration.md#email-language) explains how Baander picks the language of each email.

Users change their own email language in **Settings**. Administrators see a user's language in the admin user dialog; super administrators can change it there, and other administrators see it read-only. Operators use the CLI:

```bash
make exec cmd="php bin/console app:user:setting get alice@baander.app"
make exec cmd="php bin/console app:user:setting set alice@baander.app language da"
make exec cmd="php bin/console app:user:setting reset alice@baander.app language"
```

The command accepts an email address or UUID. `reset` removes the choice, so the user follows the server default again. See [app:user:setting](commands/app-user-setting.md).

The admin API offers the same operations. Users are addressed by UUID:

| Method | Path | Role | Purpose |
|--------|------|------|---------|
| `GET` | `/api/admin/users/{id}/settings` | `ROLE_ADMIN` | List the user's settings, including a stored value that is no longer allowed, marked `storedValueValid: false` |
| `PUT` | `/api/admin/users/{id}/settings/{key}` | `ROLE_SUPER_ADMIN` | Set the user's choice; the body is `{"value": <value>}` |
| `DELETE` | `/api/admin/users/{id}/settings/{key}` | `ROLE_SUPER_ADMIN` | Remove the user's choice |

Super administrators can set every user setting, including any that users cannot change themselves. Each change made through the admin API or the CLI writes an info log entry with the acting administrator's ID (`cli` for the command), the user's ID, the key, and the old and new stored values.

## Disabling and Enabling Users

Disable a user account to revoke access without deleting it:

```bash
make exec cmd="php bin/console app:user:disable alice@example.com"
```

Re-enable a disabled account:

```bash
make exec cmd="php bin/console app:user:enable alice@example.com"
```

Both commands accept an email address or UUID as the identifier. See [app:user:disable](commands/app-user-disable.md) and [app:user:enable](commands/app-user-enable.md) for details.

To revoke all authentication for a user in one step, rotate all secrets as described in the [security guide](security.md). This forces every user to re-authenticate.

## Deleting Users

There is no CLI command to delete user accounts. To remove a user, delete their records from the database directly. This may leave orphaned data (activity history, playlists, preferences) depending on your schema and whether cascading deletes are configured:

```sql
DELETE FROM users WHERE email = 'alice@example.com';
```

Use this with caution — there is no undo. Take a database backup before deleting any user data.

## OAuth Clients

Baander's web and desktop apps sign in through one first-party OAuth client, which [app:auth:setup-clients](commands/app-auth-setup-clients.md) creates during setup. Any other application that wants to act for a user, such as a TV app, a native player or a server-side integration, gets its tokens from Baander's OAuth 2.0 authorization server, and it must be registered as a client first. The client's ID is what the application sends as `client_id`. Registration alone grants nothing: a signed-in user still has to approve the application before it gets tokens for them.

### Client types

| Type | For | Created by |
|------|-----|------------|
| `first_party` | Baander's own web and desktop apps; password and passkey login issue tokens to it | `app:auth:setup-clients` |
| `device` | Devices without a browser or keyboard, such as TV apps; uses the device authorization grant (RFC 8628) and has no redirect URIs | An administrator |
| `public` | Native and browser apps that cannot keep a secret; uses the authorization code grant with PKCE | An administrator |
| `confidential` | Server-side applications that can keep a secret; uses the authorization code grant with PKCE and authenticates with a client secret | An administrator |
| `personal_access` | A user's own scripts and tools | The user |

Administrators manage device, public and confidential clients. The first-party client and personal access clients are outside their reach: the commands and admin endpoints below list the first-party client but refuse to change it, and they do not show personal access clients at all. Users list and revoke their own personal access clients.

### Who can manage clients

Anyone with console access can run the `app:oauth:client:*` commands. In the admin API, an administrator (`ROLE_ADMIN`) can list clients, and only a super administrator (`ROLE_SUPER_ADMIN`) can register a client, rotate its secret or revoke it. The commands and the endpoints run the same application handlers, so they apply the same rules:

| Action | Command | Admin API |
|--------|---------|-----------|
| List clients | [app:oauth:client:list](commands/app-oauth-client-list.md) | `GET /api/admin/oauth/clients` |
| Register a client | [app:oauth:client:create](commands/app-oauth-client-create.md) | `POST /api/admin/oauth/clients` |
| Rotate a confidential client's secret | [app:oauth:client:rotate-secret](commands/app-oauth-client-rotate-secret.md) | `POST /api/admin/oauth/clients/{clientId}/rotate-secret` |
| Revoke a client and its tokens | [app:oauth:client:revoke](commands/app-oauth-client-revoke.md) | `POST /api/admin/oauth/clients/{clientId}/revoke` |

### Registering a client

Register a device client with a name the user will recognize when approving it:

```bash
make exec cmd="php bin/console app:oauth:client:create 'Living room TV' --type device"
```

Public and confidential clients need at least one redirect URI, and at most 10. This is where Baander sends the user back after the decision:

```bash
make exec cmd="php bin/console app:oauth:client:create 'Baander Player' --type public --redirect-uri https://player.baander.app/callback"
```

A redirect URI must be absolute, without a fragment or credentials, and use `https`, plain `http` on a loopback host (`localhost`, `127.0.0.1` or `[::1]`), or a private-use scheme in reverse domain form such as `app.baander.tv:/callback`. A request at the authorization endpoint must name a registered URI exactly. A loopback URI also matches on any port, because native apps listen on a port the system picks. Device clients have no redirect URIs. See [app:oauth:client:create](commands/app-oauth-client-create.md) for the full rules.

### Client secrets

A confidential client gets a generated secret when it is registered. The command output or the API response shows that secret once. Baander stores only its SHA-256 digest, so nobody can read the secret back later, not even an administrator. Copy it into the client's configuration straight away.

If the secret is lost or may have leaked, rotate it. The old secret stops working at once, while the access and refresh tokens the client already holds stay valid. The client needs the new secret before its next token request. Device and public clients have no secret.

### Revoking a client

Revoking a client also revokes every access and refresh token issued to it, in the same transaction, so every user signed in through that client is signed out of it at once. Afterwards the authorization server treats the client as unknown. A revoked client stays in the list, marked as revoked, and cannot be restored; register a new client to let the application in again. Revoking an already revoked client succeeds again, so a retry is harmless.

### What users see

Both user-facing flows run through pages of the web app. A user has to be signed in to the web app to approve anything there.

**Consent page.** An application using the authorization code grant sends the user's browser to `APP_URL/oauth/authorize`, the authorization endpoint that the metadata document at `/.well-known/oauth-authorization-server` advertises. The page checks the request with the API and shows the client's name and the scopes it would get. The user approves or denies, and the page sends the browser back to the client's redirect URI with an authorization code or an `access_denied` error. If the client is unknown or revoked, or the redirect URI is missing or not registered, the page shows the error and does not redirect. Baander keeps no record of earlier consent. The page may approve a request from the first-party client or from one of the user's own personal access clients without asking; every other client asks the user each time. Another user's personal access client is refused.

**Device page.** A device client shows a short user code, such as `BCDF-GHJK`, and the address `APP_URL/device`. It may also offer a QR code of that address with the code filled in. The user opens the page, enters the code if it is not filled in already, sees which client is asking and for which scopes, and approves or denies. Case, spaces and dashes in the code do not matter. The code expires after 15 minutes by default (`auth.device_code.ttl`). On approval the device receives its tokens at its next poll, and the user gets a Security notification about the approval.

The page addresses are the `auth.oauth.authorization_page_uri` and `auth.device.verification_uri` parameters; see [Authorization server](configuration.md#authorization-server).

**Expired codes.** Authorization codes and device codes are deleted once they have been expired for more than an hour, whether they were used, denied or never touched. The hour lets a device that is still polling learn that its code expired. A scheduled job, **Purge expired OAuth codes**, does this every day at 03:30 UTC. It is created on install and can be rescheduled or paused in the scheduler admin. [`app:oauth:purge-codes`](commands/app-oauth-purge-codes.md) runs the same purge on demand.
