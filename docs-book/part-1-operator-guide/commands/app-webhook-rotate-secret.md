# app:webhook:rotate-secret

Replace a webhook's signing secret and print the new one. The command does what `POST /api/webhooks/{id}/rotate-secret` does in the admin API, through the same use case.

## Quick start

```bash
make exec cmd="php bin/console app:webhook:rotate-secret 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

Without a terminal, for a script that stores the new secret:

```bash
make exec cmd="php bin/console app:webhook:rotate-secret 0192a3b4-c5d6-7890-abcd-ef1234567890 --force --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | The webhook UUID, as [app:webhook:list](app-webhook-list.md) shows it |

## Options

| Option | Description |
|--------|-------------|
| `--force` | Rotate without asking; required when no terminal is attached |
| `--json` | Print only the result, as the admin API's `data` payload, in JSON |

## Details

The old secret stops working at once: every delivery after the rotation is signed with the new secret, so the receiver rejects them until it has the new one. On a terminal the command asks first. Without a terminal and without `--force` it changes nothing and exits with code 2.

The command prints the new secret once; store it with the receiver. It also prints the webhook's signing version, which rotation keeps. Rotate when a secret is lost or exposed, or after `APP_SECRET` changes and the stored secrets can no longer be decrypted; see [Notifications](../notifications.md).

With `--json` the command prints the API's `data` object: `id`, `secret` and `signing_version`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Secret replaced; the new secret was printed |
| 1 | No webhook has the ID, the operator declined, or another error occurred; the message says why |
| 2 | The ID is not a UUID, or no terminal is attached and `--force` was not given; the secret did not change |
