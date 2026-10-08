# app:radio:source:create

Create a radio source: a station directory that [app:radio:sync](app-radio-sync.md) pulls station data from. The command does what `POST /api/radio/sources` does in the admin API, with the same checks.

## Quick start

```bash
make exec cmd="php bin/console app:radio:source:create --name=IPRD --type=iprd --sync-url=https://iprd-org.github.io/iprd --sync-schedule='0 */6 * * *'"
```

## Options

| Option | Required | Description |
|--------|----------|-------------|
| `--name` | Yes | Source name |
| `--type` | Yes | Source type, such as `iprd` |
| `--sync-url` | Yes | URL the station data is synced from |
| `--sync-config` | No | Source configuration as a JSON object, such as `'{"region": "eu"}'`; defaults to `{}` |
| `--sync-schedule` | No | Cron expression for the sync, such as `0 */6 * * *` |

## Details

A new source starts active. On success the command prints the source name and its UUID.

The options are checked with the API request's constraints. A missing name, type or sync URL, or a sync URL that is not a valid URL, is rejected with `Validation failed.` and one line per field, such as `syncUrl: This value is not a valid URL.` These are the message and the per-field messages of the API's 422 response. Nothing is stored when the input is rejected.

`app:radio:sync --init` creates the default IPRD source when none exists, so a fresh install does not need this command.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Source created |
| 1 | The source could not be stored; the message says why |
| 2 | The input was rejected, or `--sync-config` is not a JSON object; the message says why |
