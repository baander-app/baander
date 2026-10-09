# app:webhook:update

Change a webhook's URL or the notification categories it receives. The command does what `PUT /api/webhooks/{id}` does in the admin API, through the same use case and with the same validation.

## Quick start

```bash
make exec cmd="php bin/console app:webhook:update 0192a3b4-c5d6-7890-abcd-ef1234567890 --url=https://hooks.baander.app/notify"
```

Deliver every category again, clearing the category filter:

```bash
make exec cmd="php bin/console app:webhook:update 0192a3b4-c5d6-7890-abcd-ef1234567890 --all-categories"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | The webhook UUID, as [app:webhook:list](app-webhook-list.md) shows it |

## Options

| Option | Description |
|--------|-------------|
| `--url=URL` | The new destination URL; it must resolve only to allowed addresses |
| `--category=CATEGORY` | Deliver only this notification category; repeat the option for several. The given categories replace the current ones |
| `--all-categories` | Deliver every category, clearing the category filter, as `"category_filter": null` does in the API |
| `--json` | Print only the result, as the admin API's `data` payload, in JSON |

The categories are `security`, `background_jobs`, `media_changes` and `admin_operations`.

## Details

An option that is not given leaves its field unchanged. `--category` and `--all-categories` cannot be combined. The signing secret does not change; use [app:webhook:rotate-secret](app-webhook-rotate-secret.md) for that.

The new URL passes the same checks as in [app:webhook:create](app-webhook-create.md). When the URL or a category is rejected, nothing changes, not even the field that was valid.

With `--json` the command prints the API's `data` object: `id`, `url`, `category_filter`, `created_at` and `updated_at`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Webhook updated |
| 1 | No webhook has the ID, or another error occurred; the message says why |
| 2 | The ID is not a UUID, the URL or a category was rejected, or `--category` was combined with `--all-categories`; nothing changed |
