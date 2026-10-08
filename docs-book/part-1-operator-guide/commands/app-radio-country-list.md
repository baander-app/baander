# app:radio:country:list

List the countries the radio station directory offers, with the number of stations in each. The command reads the same list as `GET /api/radio/countries`, which the admin **Radio** page calls.

## Quick start

```bash
make exec cmd="php bin/console app:radio:country:list"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the countries exactly as the API's `data` array, in JSON |

## Details

The list comes from the IPRD station directory's summary, fetched when the command runs, so it needs outbound network access. It lists the countries that can be subscribed to and synced, not the stations already synced; for those, use [app:radio:station:list](app-radio-station-list.md).

The table has one row per country:

| Column | Content |
|--------|---------|
| Code | ISO 3166-1 alpha-2 country code, which `app:radio:sync --country` takes |
| Name | The country name |
| Stations | The number of stations the directory lists for the country |

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | The directory could not be reached or answered with an error; the message says why |
