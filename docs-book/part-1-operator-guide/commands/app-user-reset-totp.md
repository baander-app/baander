# app:user:reset-totp

Turn off a user's two-factor authentication (TOTP) and sign them out of every session. Use it when a user has lost their authenticator app, or when their stored TOTP secret no longer opens because `AUTH_TOTP_ENCRYPTION_KEY` changed. Sign-in then asks for the password alone. The command runs the same use case as the admin API's `POST /api/admin/users/{publicId}/reset-totp`.

## Quick start

The Make examples below target the checkout's development preview. For an
installed instance, use the `dc` function from
[Getting started](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec app php bin/console …`.

```bash
make exec cmd="php bin/console app:user:reset-totp alice@baander.app"
```

By public ID:

```bash
make exec cmd="php bin/console app:user:reset-totp aB3dE5fG7hJ9kL1mN3pQr"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `identifier` | Yes | User's email address or public ID |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the changed user, as the admin API's `data` payload, in JSON |

## Details

The reset removes the stored TOTP secret without reading it, so it also works for a secret the configured key cannot open. In the same transaction it revokes every access and refresh token of the user, so all of their devices must sign in again. The user can set up TOTP again afterwards (`POST /api/auth/totp/setup`).

Confirm the request with the user through a channel you trust before you run the command: the reset removes their second factor.

An administrator cannot reset their own TOTP through the admin API; they turn it off with a current code (`POST /api/auth/totp/disable`). The console has no such limit.

With `--json` the command prints only the user as the API's `data` object, the same user resource `POST /api/admin/users/{publicId}/reset-totp` returns: `publicId`, `name`, `email`, `emailVerifiedAt`, `roles`, `disabled`, `createdAt` and `updatedAt`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | TOTP is off and the user's sessions have ended |
| 1 | The user was not found, the user has no TOTP to reset, or another error occurred; the message says why |
