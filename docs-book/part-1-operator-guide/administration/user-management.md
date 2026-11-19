# User Management

Use this chapter to create an account, choose its authority and end its access.
Library membership separately controls which media a user can read; see
[Library management](../media/library-management.md).

Run commands on the installation host, from its checkout, in the shell where the
[`dc` wrapper](../getting-started/installation.md) is defined. Console access carries full authority;
it does not apply the admin API's role checks. Make commands target development
instances; see the [development environment guide](../../part-2-developer-guide/getting-started/development-environment.md).

## Creating Users

Users can self-register through the public `POST /api/auth/register` endpoint, or an operator can create the account directly with the `app:user:create` command. An account the operator creates starts verified, so the user can log in right away.

Create a regular user:

```bash
dc exec app php bin/console app:user:create alice@baander.app Alice
```

Create an admin (the command asks for confirmation before granting admin privileges):

```bash
dc exec app php bin/console app:user:create admin@baander.app Admin --role admin
```

### Arguments and options

| Argument / option | Required | Default | Description |
|-------------------|----------|---------|-------------|
| `email` | Yes | — | User's email address |
| `name` | Yes | — | Display name |
| `--password` | No | — | Read password from stdin instead of prompting interactively |
| `--role` | No | `user` | Role: `user`, `admin` or `super-admin`; repeat the option for several |
| `--force` | No | — | Grant an admin role without the confirmation prompt; required when no terminal is attached |
| `--json` | No | — | Print the created user as JSON, the same data as `POST /api/admin/users` |

For scripted use, provide a protected password file through stdin with `--password`
and disable Compose terminal allocation with `-T`:

```bash
dc exec -T app php bin/console app:user:create alice@baander.app Alice --password < /secure/path/initial-password
```

See the [full command reference](../commands/app-user-create.md) for exit codes and additional details.

### Password requirements

A password must be 8 to 255 characters. There is no formal password policy beyond this — no complexity rules, no expiration, and no history. Passwords are hashed with Argon2id (memory cost: 65536, time cost: 4), a memory-hard hash that makes the hashes in a stolen database expensive to crack. See the [security guide](../maintenance/security.md#password-security) for more on password hashing.

## Roles

Baander uses three roles:

| Role | Description |
|------|-------------|
| `ROLE_USER` | Standard user — can browse libraries, stream media, create playlists, and manage their own preferences |
| `ROLE_ADMIN` | Administrator — has access to the operational and management API endpoints; some changes there need `ROLE_SUPER_ADMIN` |
| `ROLE_SUPER_ADMIN` | Super administrator — includes `ROLE_ADMIN` and may also make the changes reserved for it, such as creating administrators, changing [server settings](../configuration/server-settings.md#server-settings) and [users' settings](#user-settings), and registering, rotating secrets of, replacing keys of and revoking [OAuth clients](#oauth-clients) |

Roles apply in Baander's own web and desktop apps. A token that another application obtained through [OAuth](#oauth-clients) counts the user as `ROLE_USER` alone, whatever roles the account has, so an administrator who connects a third-party app does not hand it administrator access.

Roles are assigned at creation time with the `--role` flag. To change them later, [app:user:roles](../commands/app-user-roles.md) replaces a user's roles with the set you give; a super administrator can do the same in the admin panel or through the admin API (`POST /api/admin/users/{publicId}/roles`):

```bash
dc exec app php bin/console app:user:roles alice@baander.app ROLE_USER ROLE_ADMIN
```

### Who can manage users

Two [server settings](../configuration/server-settings.md#server-settings) decide what an administrator who is not a super administrator may do on the admin **Users** page and the `/api/admin/users` endpoints:

| Setting | Default | While it is on |
|---------|---------|----------------|
| `admin.can_view_users` | `true` | Administrators may list users. While it is off, the list request gets `403` and the page explains why. |
| `admin.can_create_users` | `false` | Administrators may create users, but only with the User role. The setting never lets an administrator create another administrator. While it is off, the page hides **Create User**. |

Baander reads both settings on every request, so a change applies at once. Super administrators may always list and create users, with any role. Every other change to an account, such as editing it, assigning roles, resetting its password, disabling, enabling or deleting it, needs `ROLE_SUPER_ADMIN`. Administrators who are not super administrators therefore get a single **View** action for each user, which opens the user dialog read-only.

The console commands are not affected by these settings: console access carries full authority.

## Passkeys (WebAuthn)

Each user registers their own passkeys in the browser through the standard WebAuthn API; operators do not manage passkeys on a user's behalf. Adding a passkey needs a verified email address and the user's current password, plus a TOTP code when TOTP is on. An account with TOTP on accepts a passkey sign-in only when the authenticator verified the user, for example with a PIN or fingerprint.

Browser and authenticator support depend on the client device. Test registration and
sign-in with the clients your installation supports.

Users can register multiple passkeys on the same account and use any of them to authenticate. If a passkey is lost or the device is unavailable, users fall back to password authentication.

## TOTP 2FA

Users enable and disable time-based one-time password (TOTP) two-factor
authentication through the API, using a compatible authenticator app.

The setup endpoint needs a verified email address and the user's current password,
and returns a secret and provisioning URI. The user confirms a code from their
authenticator before TOTP is enabled; disabling TOTP also requires a current code.
Each code works once. Baander stores the secret encrypted under
`AUTH_TOTP_ENCRYPTION_KEY`; see [Authentication](../configuration/authentication-and-limits.md#totp).

There are no recovery codes. When a user has lost their authenticator, or their
sign-in answers that TOTP is unavailable because the configured key no longer opens
their secret, turn TOTP off for them:

```bash
dc exec app php bin/console app:user:reset-totp alice@baander.app
```

The reset also signs the user out of every session. Afterwards they sign in with their
password and can set TOTP up again. Confirm the request with the user through a
channel you trust first, because the reset removes their second factor. A super
administrator can do the same through the admin API
(`POST /api/admin/users/{publicId}/reset-totp`), but not for their own account. A
password reset leaves TOTP on, unless the account's address was never verified; see
[Resetting a Password](#resetting-a-password). See
[app:user:reset-totp](../commands/app-user-reset-totp.md).

## Resetting a Password

Set a new password for a user who has forgotten theirs:

```bash
dc exec app php bin/console app:user:reset-password alice@baander.app
```

The command prompts for the password, or reads it from stdin with `--password`. It accepts an email address or public ID and does the same as **Reset password** in the admin panel. Both sign the user out of every session. See [app:user:reset-password](../commands/app-user-reset-password.md).

Users who forget their password can reset it themselves once [email is configured](../configuration/environment-variables.md#mail):

1. On the login page, the user selects **Forgot password?** and enters their email address. The page shows the same confirmation whether or not an account uses that address.
2. If an account does, Baander emails it a link to the **Choose a new password** page. The link works once and expires after `PASSWORD_RESET_EXPIRE` minutes (60 by default).
3. The user enters the new password twice. It must be 8 to 255 characters. If the link was already used, has expired, or the account is disabled, the page says the link is invalid or has expired and links to the request page.
4. Baander sets the password, signs the user out of every session, and returns to the login page with a confirmation.

Opening the link proves the user reads that mailbox. If the account's address had never been verified, someone else may have registered it, so the reset also verifies the address and removes the account's TOTP and passkeys.

By default a user can ask for a link 10 times per 15 minutes; after that the page still confirms but no email is sent. See [Password reset](../configuration/authentication-and-limits.md#password-reset) for the limits and how the link is built. API clients use the same two endpoints as the web pages: `POST /api/auth/password/reset-request` and `POST /api/auth/password/reset`.

## Verifying Email Addresses

Baander sends notification email only to verified addresses, and a user needs a verified address to add a passkey or turn on TOTP. Operator-created accounts start verified. Self-registered accounts start unverified, and Baander emails the address a verification link once [email is configured](../configuration/environment-variables.md#mail):

1. The user opens the link, which leads to the web app's **Verify your email** page. The page confirms the address, or says the link is invalid or has expired.
2. The link works once and expires after 24 hours. It verifies only the address it was sent to, so after another email change it no longer works.
3. A signed-in user whose address is unverified sees **Not verified** under the email address in **Settings** and can select **Resend verification email**. A new link replaces the earlier one. By default a user can ask 3 times per hour; after that the page still confirms but no email is sent.

On an installation without email, or for a user who cannot receive the link, mark the current address verified yourself:

```bash
dc exec app php bin/console app:user:mark-email-verified alice@baander.app
```

A super administrator can do the same through the admin API (`POST /api/admin/users/{publicId}/verify-email`). See [app:user:mark-email-verified](../commands/app-user-mark-email-verified.md).

See [Email verification](../configuration/authentication-and-limits.md#email-verification) for the lifetime, the limits and how the link is built.

## Changing an Email Address

Users change their own address in **Settings**. Operators change it in the admin panel or with the CLI:

```bash
dc exec app php bin/console app:user:change-email alice@baander.app alice.new@baander.app
```

The command accepts the current email address or the public ID. The two ways differ:

- **A user's own change** needs their current password, plus a TOTP code when TOTP is on. The new address stays pending, shown in **Settings**, and Baander emails it a verification link. The account keeps its old address, and that address's verification, until the user opens the link. The request gets the same answer whether or not another account uses the new address; if one does when the link is opened, the change is dropped.
- **An operator's change** in the admin panel or with the CLI takes effect at once and counts as verified. It drops a pending change. An administrator cannot change their own address through the admin API; they use **Settings** like any user.

When the change takes effect, Baander emails the old address that the account's address changed, and the verification and password reset links sent to the old address stop working. See [app:user:change-email](../commands/app-user-change-email.md).

## User Settings

Each user has settings of their own. Currently there is one, `language`, the language of the emails Baander sends the user and of the messages its API returns; emails follow the server default until the user or an administrator chooses a language. [Email language](../configuration/server-settings.md#email-language) explains how Baander picks the language of each email.

Users change their own language in **Settings**. Administrators see a user's language in the admin user dialog; super administrators can change it there, and other administrators see it read-only. Operators use the CLI:

```bash
dc exec app php bin/console app:user:setting get alice@baander.app
dc exec app php bin/console app:user:setting set alice@baander.app language da
dc exec app php bin/console app:user:setting reset alice@baander.app language
```

The command accepts an email address or public ID. `reset` removes the choice, so the user follows the server default again. See [app:user:setting](../commands/app-user-setting.md).

The admin API offers the same operations. Users are addressed by public ID:

| Method | Path | Role | Purpose |
|--------|------|------|---------|
| `GET` | `/api/admin/users/{publicId}/settings` | `ROLE_ADMIN` while `admin.can_view_users` is on | List the user's settings, including a stored value that is no longer allowed, marked `storedValueValid: false` |
| `PUT` | `/api/admin/users/{publicId}/settings/{key}` | `ROLE_SUPER_ADMIN` | Set the user's choice; the body is `{"value": <value>}` |
| `DELETE` | `/api/admin/users/{publicId}/settings/{key}` | `ROLE_SUPER_ADMIN` | Remove the user's choice |

Super administrators can set every user setting, including any that users cannot change themselves. Each change made through the admin API or the CLI writes an info log entry with the acting administrator's ID (`cli` for the command), the user's ID, the key, and the old and new stored values.

## Disabling and Enabling Users

Disable a user account to revoke access without deleting it:

```bash
dc exec app php bin/console app:user:disable alice@baander.app
```

Re-enable a disabled account:

```bash
dc exec app php bin/console app:user:enable alice@baander.app
```

Both commands accept an email address or public ID as the identifier. Disabling saves the
account state and revokes access and refresh tokens in one transaction, then asks
the web server to close open WebSocket connections. Run it in the app container so
it can reach that server. Enabling restores the account, but restores none of its
revoked tokens: the user signs in again. See [app:user:disable](../commands/app-user-disable.md) and [app:user:enable](../commands/app-user-enable.md) for details.

Disabling acts on one account; global credential rotation affects every user and
belongs to [Security](../maintenance/security.md). A disable does not end every media transfer
at once: a response already streaming can finish, and signed video URLs use a separate
secret. Read [Disabled accounts](../maintenance/security.md#disabled-accounts) before treating a disable as the end of every media transfer.

## Deleting Users

[app:user:delete](../commands/app-user-delete.md) deletes an account through the same use case as the admin panel. It asks for confirmation on a terminal and needs `--force` in a script:

```bash
dc exec app php bin/console app:user:delete alice@baander.app
```

Deletion cannot be undone. To stop someone from signing in while keeping their account, disable it instead.

## OAuth Clients

Baander's web and desktop apps sign in through one first-party OAuth client, which [app:auth:setup-clients](../commands/app-auth-setup-clients.md) creates during setup. Any other application that wants to act for a user, such as a TV app, a native player or a server-side integration, gets its tokens from Baander's OAuth 2.0 authorization server, and it must be registered as a client first. The client's ID is what the application sends as `client_id`. Registration alone grants nothing: a signed-in user still has to approve the application before it gets tokens for them. Those tokens are delegated: they reach only what the scopes the user approved cover, such as browsing and playing the user's libraries or managing their playlists, and never administration or account security. See [Delegated tokens and scopes](../configuration/authentication-and-limits.md#delegated-tokens-and-scopes).

### Client types

| Type | For | Created by |
|------|-----|------------|
| `first_party` | Baander's own web and desktop apps; password and passkey login issue tokens to it | `app:auth:setup-clients` |
| `device` | Devices without a browser or keyboard, such as TV apps; uses the device authorization grant (RFC 8628) and has no redirect URIs | An administrator |
| `public` | Native and browser apps that cannot keep a secret; uses the authorization code grant with PKCE | An administrator |
| `confidential` | Server-side applications that can keep a secret; uses the authorization code grant with PKCE and authenticates with a client secret or with its own signing keys | An administrator |
| `personal_access` | A user's own scripts and tools | The user |

Administrators manage device, public and confidential clients. The first-party client and personal access clients are outside their reach: the commands and admin endpoints below list the first-party client but refuse to change it, and they do not show personal access clients at all. Users list and revoke their own personal access clients.

### Who can manage clients

Anyone with console access can run the `app:oauth:client:*` commands. In the admin panel and the admin API, an administrator (`ROLE_ADMIN`) can list clients, and only a super administrator (`ROLE_SUPER_ADMIN`) can register a client, rotate its secret, replace its keys or revoke it. The admin panel's **Security → OAuth Clients** tab (`/admin/security?tab=oauth-clients`) calls the admin API. The commands and the endpoints run the same application handlers, so they apply the same rules:

| Action | Command | Admin panel | Admin API |
|--------|---------|-------------|-----------|
| List clients | [app:oauth:client:list](../commands/app-oauth-client-list.md) | The OAuth Clients tab | `GET /api/admin/oauth/clients` |
| Register a client | [app:oauth:client:create](../commands/app-oauth-client-create.md) | **Create client** | `POST /api/admin/oauth/clients` |
| Rotate a confidential client's secret | [app:oauth:client:rotate-secret](../commands/app-oauth-client-rotate-secret.md) | **Rotate secret** on the client's row | `POST /api/admin/oauth/clients/{clientId}/rotate-secret` |
| Replace a key client's keys | [app:oauth:client:replace-keys](../commands/app-oauth-client-replace-keys.md) | **Replace keys** on the client's row | `POST /api/admin/oauth/clients/{clientId}/keys` |
| Revoke a client and its tokens | [app:oauth:client:revoke](../commands/app-oauth-client-revoke.md) | **Revoke** on the client's row | `POST /api/admin/oauth/clients/{clientId}/revoke` |

### Registering a client

Register a device client with a name the user will recognize when approving it:

```bash
dc exec app php bin/console app:oauth:client:create 'Living room TV' --type device
```

Public and confidential clients need at least one redirect URI, and at most 10. This is where Baander sends the user back after the decision:

```bash
dc exec app php bin/console app:oauth:client:create 'Baander Player' --type public --redirect-uri https://player.baander.app/callback
```

A redirect URI must be absolute, without a fragment or credentials, and use `https`, plain `http` on a loopback host (`localhost`, `127.0.0.1` or `[::1]`), or a private-use scheme in reverse domain form such as `app.baander.tv:/callback`. A pushed authorization request must name a registered URI exactly. A loopback URI also matches on any port, because native apps listen on a port the system picks. Device clients have no redirect URIs. See [app:oauth:client:create](../commands/app-oauth-client-create.md) for the full rules.

### How confidential clients authenticate

A confidential client authenticates in one of two ways, chosen at registration and fixed afterwards:

- **With a secret** (`client_secret_post`, the default). Baander generates the secret; see [Client secrets](#client-secrets). The application sends it in the request body or with HTTP Basic authentication (`client_secret_basic`), never both at once.
- **With its own keys** (`private_key_jwt`). The application signs a short-lived token with a private key that never leaves it, and Baander checks the signature against the client's public keys. No shared secret exists that could leak from Baander.

To move an application from a secret to keys, or back, register a new client and revoke the old one.

### Client keys

Register a key client when the application can sign with its own key pair, and give Baander its public keys in one of two forms:

- **An inline key set.** You give Baander the application's public JWK Set when you register the client. Use this when the application's operator hands you its keys and rotates them rarely.
- **A key set URL** (`jwks_uri`). The application publishes its public keys at an `https` address, and Baander fetches them from there. Use this when the application rotates its own keys: it publishes a new key, starts signing with it, and Baander picks the key up within a minute without an administrator.

In the admin panel, choose the confidential type and the key authentication method in **Create client**, then give the key set or its URL. The admin API takes the same choice as `authMethod` with `jwks` or `jwksUri`, and [app:oauth:client:create](../commands/app-oauth-client-create.md) takes `--auth-method` with `--jwks-file` or `--jwks-uri`.

A key set holds at most 10 public keys and 64 KB, and when it holds several keys, each needs its own `kid`. Baander refuses private and symmetric keys, so a key set never carries secret material into Baander. An `OKP` key must use the `Ed25519` curve. A key set URL must use `https` on the default port, name a host by a DNS name with at least one dot rather than an IP address, and carry no user name, password or fragment. Baander fetches it only from a public address of that host, does not follow redirects, and gives up after 3 seconds. Baander caches the fetched keys, so the URL is needed only for the first fetch, after the cache expires, and when the application signs with a key Baander has not seen. If the URL cannot be reached then, the application's request answers `503` and it should retry; nothing is cached from a failed fetch.

To change the keys, use **Replace keys** on the client's row, the admin API's `POST /api/admin/oauth/clients/{clientId}/keys`, or [app:oauth:client:replace-keys](../commands/app-oauth-client-replace-keys.md). Replacing may switch a client between an inline set and a URL. The new keys apply to the application's next request, and a cached copy of the old set is never used again. Tokens the application already holds stay valid.

A key the application removes from its published set is not withdrawn at once: Baander keeps using its cached copy, for up to an hour by default, because it fetches early only for a key it has not seen. If a key leaked, replace the client's keys with the same URL right after the application removes the key; Baander then fetches the set again at the next request. A secret client has no keys to replace, and a key client has no secret to rotate.

The settings that bound key clients are in [Client assertions and key sets](../configuration/authentication-and-limits.md#client-assertions-and-key-sets).

### Client secrets

A `client_secret_post` client gets a generated secret when it is registered. The command output, the admin panel or the API response shows that secret once. Baander stores only its SHA-256 digest, so nobody can read the secret back later, not even an administrator. Copy it into the client's configuration straight away.

If the secret is lost or may have leaked, rotate it. The old secret stops working at once, while the access and refresh tokens the client already holds stay valid. The client needs the new secret before its next token request. Device, public and key clients have no secret.

### Revoking a client

Revoking a client also revokes every access and refresh token issued to it, in the same transaction, so every user signed in through that client is signed out of it at once. Afterwards the authorization server treats the client as unknown. A revoked client stays in the list, marked as revoked, and cannot be restored; register a new client to let the application in again. Revoking an already revoked client succeeds again, so a retry is harmless.

### What users see

Both user-facing flows run through pages of the web app. A user has to be signed in to the web app to approve anything there.

**Consent page.** An application using the authorization code grant first pushes its request to Baander directly, server to server, and gets back a short-lived reference to it. It then sends the user's browser to `APP_URL/oauth/authorize`, the authorization endpoint that the metadata document at `/.well-known/oauth-authorization-server` advertises, with only its client ID and that reference. The request's details, such as the scopes and the redirect URI, stay out of the browser's address bar, where they could be changed. The page shows the client's name and one line for each scope it would get. A request that asks for no scope Baander grants is refused before the user sees it. The user approves or denies, and the page sends the browser back to the client's redirect URI with an authorization code or an `access_denied` error.

The reference works for 5 minutes by default (`auth.par.request_uri_ttl`), long enough to sign in first, and only for one decision. If the client is unknown or revoked, or the reference is unknown, expired or already used, the page shows the error and does not redirect; the application has to start over. The authorization code itself lives 60 seconds by default (`auth.auth_code.ttl`) and works only for the application that holds the key its pushed request named. Baander keeps no record of earlier consent. The page may approve a request from the first-party client or from one of the user's own personal access clients without asking; every other client asks the user each time. Another user's personal access client is refused.

**Device page.** A device client shows a short user code, such as `BCDF-GHJK`, and the address `APP_URL/device`. It may also offer a QR code of that address with the code filled in. The user opens the page, enters the code if it is not filled in already, sees which client is asking and for which scopes, and approves or denies. Case, spaces and dashes in the code do not matter. The code expires after 15 minutes by default (`auth.device_code.ttl`). On approval the device receives its tokens at its next poll, and the user gets a Security notification about the approval.

The page addresses are the `auth.oauth.authorization_page_uri` and `auth.device.verification_uri` parameters; see [Authorization server](../configuration/authentication-and-limits.md#authorization-server).

**Expired codes.** Authorization codes, device codes and pushed authorization requests are deleted once they have been expired for more than an hour, whether they were used, denied or never touched. The hour lets a device that is still polling learn that its code expired. A scheduled job, **Purge expired OAuth codes**, does this every day at 03:30 UTC. It is created on install and can be rescheduled or paused in the scheduler admin. [`app:oauth:purge-codes`](../commands/app-oauth-purge-codes.md) runs the same purge on demand.
