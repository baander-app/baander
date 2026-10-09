# app:webhook:delete

Delete an outgoing webhook. The command does what `DELETE /api/webhooks/{id}` does in the admin API, through the same use case.

## Quick start

```bash
make exec cmd="php bin/console app:webhook:delete 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

Without a terminal, for example in a script:

```bash
make exec cmd="php bin/console app:webhook:delete 0192a3b4-c5d6-7890-abcd-ef1234567890 --force"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | The webhook UUID, as [app:webhook:list](app-webhook-list.md) shows it |

## Options

| Option | Description |
|--------|-------------|
| `--force` | Delete without asking; required when no terminal is attached |
| `--json` | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

On a terminal the command asks before it deletes anything. Without a terminal and without `--force` it deletes nothing and exits with code 2.

Deletion cannot be undone. Nothing is delivered to the webhook afterwards. To deliver to the same URL again, create a new webhook, which gets a new secret.

With `--json` the command prints nothing on success; read the outcome from the exit code. Errors still go to stderr. `--json` does not stand in for `--force`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Webhook deleted |
| 1 | No webhook has the ID, the operator declined, or another error occurred; the message says why |
| 2 | The ID is not a UUID, or no terminal is attached and `--force` was not given; nothing was deleted |
