# app:failed-message:flush

Remove every message from the failure transport, including messages waiting out a retry delay and rows that can no longer be decoded. The command does what `POST /api/monitor/transport/failed/flush?confirm=true` does in the admin API and what **Flush All** on the Transport Health card of the admin Job Monitor does. Both remove the messages through the same service.

## Quick start

```bash
make exec cmd="php bin/console app:failed-message:flush --force"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:failed-message:flush --force --json"
```

## Options

| Option | Description |
|--------|-------------|
| `--force` | Remove the messages without asking; required when no terminal is attached |
| `--json` | Print only the result, as the admin API's `data` payload, in JSON |

## Details

On a terminal the command asks for confirmation before it removes anything. Without a terminal, for example in a script or a cron job, it refuses to run unless `--force` is given, and removes nothing.

The command prints the number of messages it removed. Removed messages cannot be recovered.

Symfony's `messenger:failed:remove --all` skips messages waiting out a retry delay until the delay ends. This command, like the API, removes them too. To remove one message, run `messenger:failed:remove <id> --force`.

With `--json` the command prints only `{"flushed": 12}`, the API's `data` object with the number of messages removed. `--json` does not stand in for `--force`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Messages removed; the count may be 0 |
| 1 | The operator declined, or the failure transport could not be read; nothing was removed when declined |
| 2 | No terminal is attached and `--force` was not given; nothing was removed |
