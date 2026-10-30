# app:user:reset-password

Set a new password for a user and sign them out of every session. Use it when a user has forgotten their password or an account may be compromised. It does the same as **Reset password** in the admin panel (`POST /api/admin/users/{publicId}/reset-password`).

## Quick start

Run from the installed-instance host shell with the `dc` function from
[Getting started](../getting-started.md#command-context). Password files in these
examples are protected files on that host. Use `-T` for redirected input.

The command asks for the new password without echoing it:

```bash
dc exec app php bin/console app:user:reset-password alice@baander.app
```

By public ID:

```bash
dc exec app php bin/console app:user:reset-password aB3dE5fG7hJ9kL1mN3pQr
```

For scripts, redirect the protected password file to stdin with `--password`:

```bash
dc exec -T app php bin/console app:user:reset-password alice@baander.app --password < /secure/baander-user-password
```

## Arguments and options

| Argument / option | Required | Description |
|-------------------|----------|-------------|
| `identifier` | Yes | User's email address or public ID |
| `--password` | No | Read the password from stdin instead of prompting |
| `--json` | No | Print only the admin API's `data` payload, in JSON |

## What it changes

- The password must be 8 to 255 characters.
- Every access and refresh token of the user is revoked, so all of their devices must sign in again.
- An outstanding password reset token for the user is removed.
- The user receives a security notification that their password changed.

With `--json` the command prints only `{"message": "Password reset successfully."}`, the `data` object of the API's response. The password prompt goes to stderr, so stdout carries only the JSON.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Password reset |
| 1 | The user was not found, or another error occurred; the message says why |
| 2 | No password was given, or the password is not 8 to 255 characters; nothing was changed |
