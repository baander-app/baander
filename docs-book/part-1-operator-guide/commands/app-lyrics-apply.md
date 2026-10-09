# app:lyrics:apply

Store an LRCLIB search result as the lyrics of a song that has none. The command does what choosing a result on the **Search** tab of a song's lyrics dialog does, `POST /api/lyrics/search/{resultId}/apply` in the API. Find the result ID with [app:lyrics:search](app-lyrics-search.md).

## Quick start

```bash
make exec cmd="php bin/console app:lyrics:apply 912345 V1StGXR8_Z5jdHi6B-myT"
```

Print the stored lyrics as JSON, for a script:

```bash
make exec cmd="php bin/console app:lyrics:apply 912345 V1StGXR8_Z5jdHi6B-myT --json"
```

## Arguments

| Argument | Description |
|----------|-------------|
| `result-id` | The LRCLIB result ID, as `app:lyrics:search` lists it |
| `song` | The song's public ID, the 21-character ID in the song's web address |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--json` | off | Print only the API's `data` payload: the stored lyrics |

## Details

The command finds the song in any library; it does not need library access. It fetches the result from LRCLIB by ID, stores its plain and synced lyrics with LRCLIB as the source, and prints them.

A song that already has lyrics keeps them: the command reports a conflict and exits with 1, and the API answers 409. No command or API route replaces stored lyrics. Each LRCLIB result can be the lyrics of one song only, so applying a result that another song already has is refused the same way.

When LRCLIB has no result with the ID, the command exits with 1 and the API answers 404. When LRCLIB cannot be reached, answers with an error, or returns something unreadable, the command reports that LRCLIB is unavailable and exits with 1; the API answers 503. In each case nothing is stored.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The lyrics were stored |
| 1 | No song has the public ID, the song already has lyrics, another song has the result, LRCLIB has no result with the ID, or LRCLIB is unavailable; the message says which |
| 2 | The result ID is not an integer, or the public ID is malformed |
