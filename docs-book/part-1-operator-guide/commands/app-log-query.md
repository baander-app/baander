# app:log:query

Run bounded read-only SQL over an admitted log snapshot. This command reads the same
application port as `POST /api/admin/logs/query`. The SQL relation is `logs`;
column names use snake case.

## Quick start

```bash
make exec cmd="php bin/console app:log:query 'SELECT level, count(*) FROM logs GROUP BY level' --json"
```

## Arguments

| Argument | Description |
|----------|-------------|
| `sql` | Required read-only SQL statement |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print exactly the API data payload on stdout |
| `--allow-partial` | Explicitly accept incomplete source coverage |
| `--source=ID` | Restrict admitted sources; repeat for more sources |
| `--level=LEVEL` | Restrict levels; repeat for more levels |
| `--search=TEXT` | Literal message search |
| `--channel=NAME` | Match any selected channel; repeat for more channels |
| `--correlation-id=ID` | Restrict the correlation ID |
| `--since=TIME` | Start timestamp in RFC 3339 format |
| `--until=TIME` | End timestamp in RFC 3339 format |
| `--order=ORDER` | `asc` or `desc`; default `desc` |

The query refuses incomplete input unless `--allow-partial` is supplied. Results
carry coverage so an explicitly partial result can be identified. Filters select
the input snapshot before SQL runs. Browse pagination options do not apply to SQL;
the query service enforces its own snapshot and result bounds. Archive records are included through admitted
source IDs, using the same filtering contract as current records. SQL cannot write
to the log index or access application databases or arbitrary files.

The command implementation is
[`LogQueryCommand`](../../../src/Shared/Interface/Console/Log/LogQueryCommand.php).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The query response was printed |
| 1 | Coverage or query execution failed |
| 2 | Filters or SQL were rejected |

Errors go to stderr; `--json` stdout contains only the API data payload.
