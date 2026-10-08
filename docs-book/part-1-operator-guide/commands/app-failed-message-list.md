# app:failed-message:list

List the messages held by the failure transport, newest first, one page at a time. The list includes messages waiting out a retry delay. The command shows what `GET /api/monitor/transport/failed` returns in the admin API; both read the failure transport through the same service.

## Quick start

```bash
make exec cmd="php bin/console app:failed-message:list"
```

The second page of 20 messages each:

```bash
make exec cmd="php bin/console app:failed-message:list --page=2 --limit=20"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--page` | `1` | Page number; values below 1 read page 1 |
| `--limit` | `50` | Messages per page; values outside 1-100 are clamped |
| `--json` | off | Print the `data` array of `GET /api/monitor/transport/failed` as JSON instead of a table |

## Details

The command prints one row per message:

| Column | Content |
|--------|---------|
| ID | The failure transport ID that `messenger:failed:show`, `messenger:failed:retry` and `messenger:failed:remove` take |
| Message | The message's class name |
| Transport | The transport the message failed on, or `-` |
| Error | The short exception class and message of the last failure, or `-` |
| Failed at | Time of the last failure in ISO 8601 format, or `-` |
| Retries | Retries from the failure transport that failed again |

Below the table the command prints the page number, the number of pages, and the total number of failed messages. When the failure transport is empty it prints `The failure transport holds no messages.`

Symfony's `messenger:failed:show` without an ID skips messages waiting out a retry delay until the delay ends. This command, like the API, lists them. To see one message in full, run `messenger:failed:show <id>`.

With `--json`, stdout holds only the `data` array of the API response. The paging figures from the API's `meta` object are not printed.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | The failure transport could not be read; the message says why |
