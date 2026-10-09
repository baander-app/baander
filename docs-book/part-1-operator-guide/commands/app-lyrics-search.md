# app:lyrics:search

Search LRCLIB for lyrics by keywords and list the results with their IDs. The command does what the **Search** tab of a song's lyrics dialog does, `GET /api/lyrics/search` in the API. Pass a result's ID to [app:lyrics:apply](app-lyrics-apply.md) to store it as a song's lyrics.

## Quick start

```bash
make exec cmd="php bin/console app:lyrics:search Still Alive GLaDOS"
```

Print the results as JSON, for a script:

```bash
make exec cmd="php bin/console app:lyrics:search Still Alive GLaDOS --json"
```

## Arguments

| Argument | Description |
|----------|-------------|
| `query` | The search keywords, such as the title and the artist. Several words form one query; quotes are optional |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--json` | off | Print only the API's `data` payload: the results, including their full lyrics |

## Details

The table lists each result's LRCLIB ID, track, artist, album and duration, and whether it has synced lyrics or is instrumental. When nothing matches, the command says so and exits with 0, as the API answers 200 with an empty list.

When LRCLIB cannot be reached, answers with an error, or returns something unreadable, the command reports that LRCLIB is unavailable and exits with 1. The API answers 503.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The search ran; the table lists the results, possibly none |
| 1 | LRCLIB is unavailable |
| 2 | No keywords were given |
