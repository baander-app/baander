# app:user:reset-password

Set a new password for a user and sign them out of every session. Use it when a user has forgotten their password or an account may be compromised. It does the same as **Reset password** in the admin panel (`POST /api/admin/users/{id}/reset-password`).

## Quick start

The command asks for the new password without echoing it:

```bash
make exec cmd="php bin/console app:user:reset-password alice@baander.app"
```

By UUID:

```bash
make exec cmd="php bin/console app:user:reset-password 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

For scripts, pipe the password on stdin with `--password`:

```bash
echo "new-secure-password" | make exec cmd="php bin/console app:user:reset-password alice@baander.app --password"
```

## Arguments and options

| Argument / option | Required | Description |
|-------------------|----------|-------------|
| `identifier` | Yes | User's email address or UUID |
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
