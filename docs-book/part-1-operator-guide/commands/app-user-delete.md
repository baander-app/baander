# app:user:delete

Delete a user account. The command runs the same use case as deleting a user in the admin panel (`DELETE /api/admin/users/{id}` in the admin API). To stop someone from signing in while keeping their account, use [app:user:disable](app-user-disable.md) instead.

## Quick start

```bash
make exec cmd="php bin/console app:user:delete alice@baander.app"
```

Without a terminal, for example in a script:

```bash
make exec cmd="php bin/console app:user:delete alice@baander.app --force"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `identifier` | Yes | The user's email address or UUID |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--force` | — | Delete without asking. Required when no terminal is attached |

## Details

Deletion cannot be undone. On a terminal, the command asks before it deletes; answering no leaves the account as it was. Without a terminal and without `--force`, the command deletes nothing and exits with code 2.

The command acts with full authority. It can delete any account, an admin's or a super admin's included, and it does not check whether another super admin remains. If the last super admin is deleted, create a new one with [app:user:create](app-user-create.md) and `--role=super-admin`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | User deleted |
| 1 | No user has the email address or UUID, the operator declined, or another error occurred; the message says why |
| 2 | No terminal is attached and `--force` was not given; nothing was deleted |
