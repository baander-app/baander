# app:webhook:list

List the configured outgoing webhooks. The command does what `GET /api/webhooks/` does in the admin API, through the same use case. Webhooks have no admin page; this command and the API are the ways to see them.

## Quick start

```bash
make exec cmd="php bin/console app:webhook:list"
```

Print the list as JSON, for a script:

```bash
make exec cmd="php bin/console app:webhook:list --json"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the list, as the admin API's `data` array, in JSON |

## Details

The table shows each webhook's UUID, URL, the notification categories it receives (`all` when it has no category filter), and when it was created and last updated. Webhooks are listed oldest first.

Neither the table nor the JSON shows a signing secret. The secret is printed only by [app:webhook:create](app-webhook-create.md) and [app:webhook:rotate-secret](app-webhook-rotate-secret.md); if it is lost, rotate it.

With `--json` each webhook is an object with `id`, `url`, `category_filter`, `created_at` and `updated_at`, as the API returns it.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The list was printed, even when no webhook is configured |
| 1 | An error occurred; the message says why |
