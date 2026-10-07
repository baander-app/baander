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

## What it changes

- The password must be 8 to 255 characters.
- Every access and refresh token of the user is revoked, so all of their devices must sign in again.
- An outstanding password reset token for the user is removed.
- The user receives a security notification that their password changed.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Password reset |
| 1 | Failure: no password was given, the user was not found, the password breaks the policy, or another error occurred (the message is printed) |
