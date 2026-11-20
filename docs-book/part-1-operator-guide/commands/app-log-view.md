# app:log:view

Browse current and archived records through the same application port as
`GET /api/admin/logs/entries`. Inspect one record with `--detail`, matching
`GET /api/admin/logs/entries/{id}`.

## Quick start

```bash
make exec cmd="php bin/console app:log:view"
make exec cmd="php bin/console app:log:view --plain --level ERROR --limit 50"
make exec cmd="php bin/console app:log:view --source SOURCE_ID --json"
make exec cmd="php bin/console app:log:view --detail RECORD_ID --json"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print exactly the API data payload on stdout |
| `--plain` | Print one bounded response without terminal controls |
| `--detail=ID` | Read one opaque record ID |
| `--source=ID` | Restrict to an admitted source; repeat for more sources |
| `--level=LEVEL` | Restrict levels; repeat for more levels |
| `--search=TEXT` | Literal message search |
| `--channel=NAME` | Match any selected channel; repeat for more channels |
| `--correlation-id=ID` | Restrict the correlation ID |
| `--since=TIME` | Start timestamp in RFC 3339 format |
| `--until=TIME` | End timestamp in RFC 3339 format |
| `--limit=N` | Maximum records, 1–500; default 200 |
| `--cursor=CURSOR` | Continue a matching snapshot with an opaque cursor |
| `--order=ORDER` | `asc` or `desc`; default `desc` |

## Interactive controls

A terminal attached to both stdin and stdout opens the fullscreen viewer. Piped
output, noninteractive input, `--plain` and `--json` print one response and exit.
Coverage remains visible alongside records. Archives use admitted source IDs;
this command does not accept arbitrary filesystem paths.

| Key | Action |
|-----|--------|
| Arrows, `j`, `k` | Select a row or scroll the detail panel |
| Page Up, Page Down | Move by a screen |
| `g`, `G`, Home, End | Move to the first or last row |
| Enter | Open the selected record's full detail |
| `b` | Return from a panel to the records |
| `]` | Read the next cursor page |
| `/` | Submit a literal message search |
| `n`, `N` | Select the next or previous matching row |
| `s` | List sources and prompt for comma-separated source IDs |
| `f` | Enter comma-separated levels |
| `c` | Enter comma-separated channels; match any selected channel |
| `r` | Enter a correlation ID |
| `t`, `u` | Enter the start or end timestamp |
| `:` | Run read-only SQL against the current filters |
| `a` | Toggle explicit acceptance of partial SQL coverage; initially refused |
| `F` | Toggle follow, refreshing every five seconds |
| `?`, `h` | Show help |
| Ctrl-G | Cancel a prompt |
| `q`, Ctrl-C, Ctrl-D | Exit and restore terminal settings |

Submit a prompt with Enter. An empty source, level or channel prompt selects all. Multiple channels match any
selected channel. An empty correlation or timestamp prompt clears that filter. Changing a filter
resets pagination and pauses follow. Resize is read on each render. Record text
is clipped to terminal display width and control sequences are removed from the
interactive and plain displays. JSON preserves the API's original text.

The command and terminal behavior are defined in
[`LogViewCommand`](../../../src/Shared/Interface/Console/Log/LogViewCommand.php) and
[`LogTerminalViewer`](../../../src/Shared/Interface/Console/Log/LogTerminalViewer.php).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The response was printed or the viewer closed |
| 1 | A record was unavailable or the request failed |
| 2 | Input was rejected |

Errors go to stderr. During interactive use, rejected prompts appear in the status
line so the session can continue. SIGINT and SIGTERM close the viewer and restore
the terminal.
