# app:song:lyrics:fetch

Fetch the lyrics of one song from LRCLIB and store them. The command does what **Fetch from LRCLIB** in a song's lyrics dialog does, `POST /api/songs/{publicId}/lyrics/fetch` in the API, through the same fetch. To backfill every song without lyrics, use [app:lyrics:fetch](app-lyrics-fetch.md).

## Quick start

```bash
make exec cmd="php bin/console app:song:lyrics:fetch V1StGXR8_Z5jdHi6B-myT"
```

Print the lyrics as JSON, for a script:

```bash
make exec cmd="php bin/console app:song:lyrics:fetch V1StGXR8_Z5jdHi6B-myT --json"
```

## Arguments

| Argument | Description |
|----------|-------------|
| `song` | The song's public ID, the 21-character ID in the song's web address |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--json` | off | Print only the API's `data` payload: the lyrics, or `[]` when none were found |

## Details

The command finds the song in any library; it does not need library access. It looks the song up on LRCLIB by title, artist, album and duration, first in LRCLIB's own database and then through LRCLIB's external sources, and stores the first lyrics it finds. It then prints the source, whether the lyrics are synced or the track is instrumental, and the plain lyrics.

A song that already has lyrics keeps them. The command prints the stored lyrics without asking LRCLIB, as the API does. No command or API route replaces stored lyrics.

LRCLIB needs the song's artist and duration. When the song lacks either, or LRCLIB has no lyrics for it, the command says that no lyrics were found and stores nothing. It still exits with 0, as the API answers 200 with an empty `data`.

When LRCLIB cannot be reached, answers with an error, or returns something unreadable, the command reports that LRCLIB is unavailable and exits with 1. The API answers 503. Nothing is stored, and a later run may succeed.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The song has lyrics, or none were found; the message says which |
| 1 | No song has the public ID, or LRCLIB is unavailable |
| 2 | The public ID is malformed |
