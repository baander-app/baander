# app:log:sources

List admitted log sources, configured nodes and collection coverage. This command
reads the same application port as `GET /api/admin/logs/sources`.

## Quick start

```bash
make exec cmd="php bin/console app:log:sources --json"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print exactly the API data payload on stdout |

Sources include retained archives when the collector has admitted them. The returned
coverage describes missing history or unavailable nodes; source discovery alone
does not establish complete collection. Use source IDs with `app:log:view --source`
or `app:log:query --source`.

The command implementation is
[`LogSourcesCommand`](../../../src/Shared/Interface/Console/Log/LogSourcesCommand.php).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The source response was printed |
| 1 | The source request failed |
| 2 | Input was rejected |
