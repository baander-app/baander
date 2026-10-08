# app:radio:station:list

List the radio stations synced from the radio sources, optionally narrowed to a country or a search text. The command reads the same stations as `GET /api/radio/stations`, which the admin **Radio** page calls.

## Quick start

```bash
make exec cmd="php bin/console app:radio:station:list --country=DK"
```

Search by name:

```bash
make exec cmd="php bin/console app:radio:station:list --query=jazz"
```

## Options

| Option | Description |
|--------|-------------|
| `--country` | Only stations of this ISO 3166-1 alpha-2 country code, such as `DK` |
| `--query` | Only stations whose name matches this text; combines with `--country` |
| `--json` | Print the stations exactly as the API's `data` array, in JSON |

## Details

The options match the API's `country` and `q` query parameters. An empty value means no filter, as in the API. Without either option the command lists every synced station.

The table has one row per station:

| Column | Content |
|--------|---------|
| ID | The station UUID |
| Name | The station name |
| Country | The country code |
| Language | The broadcast language, or `-` |
| Genres | The station's genres, comma separated, or `-` |
| Streams | The number of stream URLs |

When no station matches, the command prints `No radio stations match.` Stations appear only after a sync; see [app:radio:sync](app-radio-sync.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | The stations could not be read; the message says why |
