# app:metadata:file:extract

Read the tags embedded in a media file on the server and print them, using the tag readers the library scan uses. The command does what the administrator-only route `POST /api/metadata/extract` does in the API. It changes nothing.

## Quick start

```bash
make exec cmd="php bin/console app:metadata:file:extract /music/Portal/01-still-alive.flac"
```

Print the tags as JSON, for a script:

```bash
make exec cmd="php bin/console app:metadata:file:extract /music/Portal/01-still-alive.flac --json"
```

## Arguments

| Argument | Description |
|----------|-------------|
| `path` | Absolute path of a media file inside a library, as the container the command runs in sees it |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--json` | off | Print only the API's `data` payload: the format and the tags |

## Details

The command detects the format from the file's first bytes, or from its extension when those match no known format. It reads ID3 tags in MP3 and M4A files, Vorbis comments in FLAC, Ogg Vorbis and Opus files, and LIST INFO tags in WAV files. An Opus file is reported as `ogg`, its container.

The command prints the format, then one row per tag: title, artist, album, album artist, track and disc number, year, genres, composer, BPM, duration in seconds, bitrate in kbps, and the MusicBrainz recording, release and artist IDs. A tag the file does not carry shows `-`. The artist and album artist are the first values the file carries. The MusicBrainz artist ID appears only when the file carries exactly one.

The command reads only files inside a library's folder. A symlink counts where its target lies, so a link inside a library that points outside every library is refused. A path outside every library gets the message a missing file gets, so neither the command nor the API reveals which paths exist on the server.

When no library holds a file at the path, or the file is in a format no tag reader supports, the command says so and exits with 2. The API answers 422 with the same message.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The tags were printed |
| 2 | The path is empty, no library holds a file at the path, or the format is not supported; the message says which |
