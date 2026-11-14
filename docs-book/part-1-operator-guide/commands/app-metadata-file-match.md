# app:metadata:file:match

Read the tags of a media file on the server and rank candidate records by how well they fit them, best first. The command does what the administrator-only route `POST /api/metadata/match` does in the API. It reads the file as [app:metadata:file:extract](app-metadata-file-extract.md) does and changes nothing.

## Quick start

```bash
make exec cmd="php bin/console app:metadata:file:match /music/Portal/01-still-alive.flac --candidates='[{\"title\": \"Still Alive\", \"artist\": \"GLaDOS\", \"album\": \"Portal\", \"sourceId\": \"a1\"}]'"
```

Print the ranked candidates as JSON, for a script:

```bash
make exec cmd="php bin/console app:metadata:file:match /music/Portal/01-still-alive.flac --candidates='[{\"title\": \"Still Alive\"}]' --json"
```

## Arguments

| Argument | Description |
|----------|-------------|
| `path` | Path of the media file, as the container the command runs in sees it |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--candidates` | none | The records to compare, as a JSON array of objects. Each has a `title` and may have `artist`, `album`, `year`, `mbid`, `source` and `sourceId` |
| `--json` | off | Print only the API's `data` payload: the candidates that fit, best first |

## Details

The command compares each candidate's title, artist and album with the file's tags and gives the candidate a confidence between 0 and 1, in which the title counts for 0.4, the artist for 0.3 and the album for 0.3. A candidate at 0.2 or below is left out. The table lists each remaining candidate's confidence, source, source ID, title, artist, album, year and MusicBrainz ID. When no candidate fits, the command says so and exits with 0, as the API answers 200 with an empty list.

The command needs at least one candidate. Without `--candidates`, or with an empty array, it prints the API's validation messages and exits with 2; so does a value that is not a JSON array of objects.

When no file exists at the path, or the file is in a format no tag reader supports, the command says so and exits with 2. The API answers 422 with the same message.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The candidates were ranked; the table lists those that fit, possibly none |
| 2 | The path is empty, the candidates are missing or malformed, no file exists at the path, or the format is not supported; the message says which |
