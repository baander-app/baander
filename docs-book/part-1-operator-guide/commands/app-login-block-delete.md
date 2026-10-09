# app:login-block:delete

Remove one login block, or every block with `--all`. The command does what `DELETE /api/admin/login-blocks/{id}` and `DELETE /api/admin/login-blocks` do in the admin API, through the same use cases. It needs no administrator account, so it also works when nobody can reach the admin panel.

## Quick start

Remove one block:

```bash
make exec cmd="php bin/console app:login-block:delete 0199c3a2-6f1e-7a35-9d4b-2f6c8e1a4b70"
```

Remove every block without a terminal, for example in a script:

```bash
make exec cmd="php bin/console app:login-block:delete --all --force"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Unless `--all` is given | The UUID of the block to remove, as [app:login-block:list](app-login-block-list.md) shows it |

## Options

| Option | Description |
|--------|-------------|
| `--all` | Remove every block instead of one |
| `--force` | With `--all`, remove without asking; required when no terminal is attached |
| `--json` | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

Give either a block ID or `--all`, not both.

Removing one block happens at once. With `--all`, the command asks on a terminal before it removes anything. Without a terminal and without `--force` it removes nothing and exits with code 2. Removal cannot be undone.

With `--json` the command prints nothing on success, because the API answers both deletions with `204 No Content`; read the outcome from the exit code. Errors still go to stderr. `--json` does not stand in for `--force`.

A block is a record of a honeypot hit. Removing it clears the record; the next attempt that fills in the honeypot field is recorded again.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The block, or every block, was removed |
| 1 | No block has the ID, the ID is not a UUID, the operator declined, or another error occurred; the message says why |
| 2 | Neither or both of an ID and `--all` were given, or `--all` was given without a terminal and without `--force`; nothing was removed |
