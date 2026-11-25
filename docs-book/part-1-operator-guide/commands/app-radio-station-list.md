# app:radio:station:list

List the radio stations synced from the radio sources, optionally narrowed to a country or a search text. The command reads the same pages as `GET /api/radio/stations`, which the admin **Radio** page calls, and lists every matching station with `--all`.

## Quick start

```bash
make exec cmd="php bin/console app:radio:station:list --country=DK"
```

Search by name:

```bash
make exec cmd="php bin/console app:radio:station:list --query=jazz"
```

Every station of a country, however many pages it takes:

```bash
make exec cmd="php bin/console app:radio:station:list --country=DK --all"
```

## Options

| Option | Description |
|--------|-------------|
| `--country` | Only stations of this ISO 3166-1 alpha-2 country code, such as `DK` |
| `--query` | Only stations whose name contains this text; combines with `--country` |
| `--limit` | Stations per page, from 1 to 500; default `50` |
| `--offset` | Matching stations to skip; default `0` |
| `--all` | Every matching station, read 500 at a time; `--limit` and `--offset` are ignored |
| `--json` | Print the stations exactly as the API's `data` array, in JSON |

## Details

The options match the API's `country`, `q`, `limit` and `offset` query parameters. An empty filter value means no filter, as in the API. Stations are ordered by name and then by id, and the search text matches part of the name case-insensitively, with `%` and `_` taken literally. Without `--all` the command prints one page and, below the table, which stations of how many it shows. A `--limit` outside 1 to 500 or a negative `--offset` is refused, as the API refuses it with `400`. With `--all` and `--json`, stdout holds one array of every matching station.

The table has one row per station:

| Column | Content |
|--------|---------|
| ID | The persisted station public ID |
| Name | The station name |
| Country | The country code |
| Language | The broadcast language, or `-` |
| Genres | The station's genres, comma separated, or `-` |
| Streams | The number of stream URLs |

The station public ID addresses the local resource. The JSON field `externalId` identifies the provider's entry and is separate from the local public ID.

When no station matches, the command prints `No radio stations match.` Stations appear only after a sync; see [app:radio:sync](app-radio-sync.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | The stations could not be read; the message says why |
| 2 | `--limit` or `--offset` was refused |
