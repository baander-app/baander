# app:user:delete

Delete a user account. The command runs the same use case as deleting a user in the admin panel (`DELETE /api/admin/users/{publicId}` in the admin API). To stop someone from signing in while keeping their account, use [app:user:disable](app-user-disable.md) instead.

## Quick start

The Make examples below target the checkout's development preview. For an
installation, use the `dc` function from
[Installation](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec web php bin/console …`.

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
| `identifier` | Yes | The user's email address or public ID |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--force` | — | Delete without asking. Required when no terminal is attached |
| `--json` | — | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

Deletion cannot be undone. On a terminal, the command asks before it deletes; answering no leaves the account as it was. Without a terminal and without `--force`, the command deletes nothing and exits with code 2.

With `--json` the command prints nothing on success, because the API answers `204 No Content`; read the outcome from the exit code. Errors still go to stderr. `--json` does not stand in for `--force`.

The command acts with full authority and can delete any account, an admin's or a super admin's included, except the only enabled super admin: the install keeps one. The admin API refuses that deletion the same way, with `409` and `details.reason` `last_super_admin`. To delete the account, first make another user a super admin with [app:user:roles](app-user-roles.md). A disabled super admin does not count. An install with no enabled super admin refuses nothing; create one with [app:user:create](app-user-create.md) and `--role=super-admin`. Deletions, disables and demotions take turns, so two of them running at the same moment cannot remove the last two super admins between them.

After the deletion is saved, the web server closes the user's open WebSocket connections on every worker. The command reaches the server through its control socket, so run it in the web container, as `make exec` does. In a container without a web server, the command still deletes the account and logs a warning that it could not reach the open connections; the web server's socket reconciliation closes them within a minute. If the web server cannot close them, the command fails with a message that says so; the account stays deleted, and the reconciliation closes the connections on its next run.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | User deleted |
| 1 | No user has the email address or public ID, the user is the only enabled super admin, the operator declined, the web server did not close the user's open WebSocket connections (the account is deleted all the same), or another error occurred; the message says why |
| 2 | No terminal is attached and `--force` was not given; nothing was deleted |
