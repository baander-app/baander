# app:webhook:create

Add an outgoing webhook and print its signing secret. The command does what `POST /api/webhooks/` does in the admin API, through the same use case and with the same validation.

## Quick start

```bash
make exec cmd="php bin/console app:webhook:create https://hooks.baander.app/notify --category=security --category=media_changes"
```

Print the result as JSON, for a script that stores the secret:

```bash
make exec cmd="php bin/console app:webhook:create https://hooks.baander.app/notify --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `url` | Yes | The destination URL; it must resolve only to allowed addresses |

## Options

| Option | Description |
|--------|-------------|
| `--category=CATEGORY` | Deliver only this notification category; repeat the option for several. Without it, the webhook receives every category |
| `--json` | Print only the result, as the admin API's `data` payload, in JSON |

The categories are `security`, `background_jobs`, `media_changes` and `admin_operations`.

## Details

The command prints the new webhook's UUID and the signing secret. This is the only time the secret is shown: store it with the receiver now. Baander keeps it encrypted with `APP_SECRET` and never prints it again. A lost secret can only be replaced with [app:webhook:rotate-secret](app-webhook-rotate-secret.md). The command also prints the signing version the webhook's deliveries use; see [Notifications](../notifications.md) for verifying signatures.

Every address the URL's host resolves to must be public, or a LAN address listed in `WEBHOOK_LAN_ALLOWLIST`; loopback, link-local and metadata addresses are never allowed. The command rejects a blank or malformed URL, a URL with embedded credentials, a host with any other address, and an unknown category, and stores nothing. The API answers the same cases with `422` and the same message.

With `--json` the command prints the API's `data` object: `id`, `url`, `category_filter`, `secret`, `signing_version`, `created_at` and `updated_at`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Webhook created; the secret was printed |
| 1 | An error occurred; the message says why |
| 2 | The URL or a category was rejected; nothing was stored |
