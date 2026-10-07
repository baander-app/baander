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
