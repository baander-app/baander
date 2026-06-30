# User Management

How to create user accounts, assign roles, and manage authentication methods.

## Creating Users

Users are created via the `app:user:create` command. This is the only supported way to add accounts — there is no self-registration endpoint. Users created this way are immediately verified and can log in right away.

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

The only enforced requirement is a minimum of 8 characters. There is no formal password policy beyond this — no complexity rules, no expiration, and no history. Passwords are hashed with Argon2id (memory cost: 65536, time cost: 4), which provides strong protection even if the database is compromised. See the [security guide](security.md#password-security) for more on password hashing.

## Roles

Baander uses two roles:

| Role | Description |
|------|-------------|
| `ROLE_USER` | Standard user — can browse libraries, stream media, create playlists, and manage their own preferences |
| `ROLE_ADMIN` | Administrator — has full access to all API endpoints, including operational and management features |

Roles are assigned at creation time with the `--role` flag. There is currently no CLI command to change a user's role after creation. To modify roles, you must update the `roles` column directly in the database:

```sql
UPDATE users SET roles = '["ROLE_USER", "ROLE_ADMIN"]' WHERE email = 'alice@example.com';
```

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

## Disabling and Deleting Users

There is currently no CLI command to disable or delete user accounts. If you need to remove access or clean up an account, you must operate directly against the database.

To revoke all authentication for a user without deleting the account, rotate all secrets as described in the [security guide](security.md). This forces every user to re-authenticate.

To delete a user, remove their records from the database. Be aware that this may leave orphaned data (activity history, playlists, preferences) depending on your database schema and whether cascading deletes are configured:

```sql
DELETE FROM users WHERE email = 'alice@example.com';
```

Use this with caution — there is no undo. Take a database backup before deleting any user data.
