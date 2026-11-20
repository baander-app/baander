# User Management

Use this page to create an account, assign its authority, change its settings or
end its access. [Account recovery](account-recovery.md) covers lost credentials and
email changes; [OAuth clients](oauth-clients.md) covers applications acting for users.
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
| `ROLE_SUPER_ADMIN` | Super administrator — includes `ROLE_ADMIN` and may also make the changes reserved for it, such as creating administrators, changing [server settings](../configuration/server-settings.md#server-settings) and [users' settings](#user-settings), and registering, rotating secrets of, replacing keys of and revoking [OAuth clients](oauth-clients.md#oauth-clients) |

Roles apply in Baander's own web and desktop apps. A token that another application obtained through [OAuth](oauth-clients.md#oauth-clients) counts the user as `ROLE_USER` alone, whatever roles the account has, so an administrator who connects a third-party app does not hand it administrator access.

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

## Recover access or change sign-in details

Use [Account recovery and sign-in methods](account-recovery.md) for:

- [Passkeys](account-recovery.md#passkeys-webauthn) and [TOTP](account-recovery.md#totp-2fa), including a lost authenticator.
- [Password resets](account-recovery.md#resetting-a-password), with or without working email.
- [Email verification](account-recovery.md#verifying-email-addresses) and [address changes](account-recovery.md#changing-an-email-address).

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
secret. Read [Disabled accounts](sessions-and-access.md#disabled-accounts) before treating a disable as the end of every media transfer.

## Deleting Users

[app:user:delete](../commands/app-user-delete.md) deletes an account through the same use case as the admin panel. It asks for confirmation on a terminal and needs `--force` in a script:

```bash
dc exec app php bin/console app:user:delete alice@baander.app
```

Deletion cannot be undone. To stop someone from signing in while keeping their account, disable it instead.

## OAuth Clients

An application acting for a user needs a registered client and the user's approval.
[Manage OAuth clients](oauth-clients.md) covers registration, client keys and secrets,
revocation and the approval pages. Delegated tokens do not carry administrator authority.
