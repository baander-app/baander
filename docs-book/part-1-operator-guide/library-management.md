# Library Management

Baander organizes your media into libraries. Each library points to a directory on disk and has a type that determines how files are indexed and what metadata is extracted. This page covers creating libraries, scanning them, watching for changes, and extracting cover art.

## Creating Libraries

Use the `app:library:create` command to register a new media library. You must provide a name, the absolute path to the media directory, and the library type.

```bash
make exec cmd="php bin/console app:library:create 'My Music' /data/music music"
```

| Argument | Description |
|----------|-------------|
| `name` | Human-readable library name |
| `path` | Absolute path to the media directory on disk |
| `type` | Library type: `music`, `podcast`, `audiobook`, `movie`, or `tv_show` |

The slug is auto-generated from the name (lowercased, spaces replaced with hyphens, special characters removed). Provide `--slug` to override it:

```bash
make exec cmd="php bin/console app:library:create 'My Music' /data/music music --slug my-music-collection"
```

The library path must be accessible from inside the app container. Mount the directory as a volume in `docker-compose.yml` before creating the library.

See the [full command reference](commands/app-library-create.md) for all options including `--sort-order`.

## Scanning

After creating a library, run a scan to index its contents:

```bash
make exec cmd="php bin/console app:library:scan my-music"
```

The scan performs these steps:

1. **File discovery** -- recursively walks the library directory and collects all files with recognized media extensions
2. **Metadata extraction** -- reads tags from audio files (title, artist, album, track number, duration) and video files (title, duration, resolution)
3. **External enrichment** -- queries external APIs to fill in missing metadata such as genre, year, cover art URLs, and artist biographies

Scanning is idempotent. Running it multiple times on the same library will update existing records and add new files without creating duplicates. Removed files are cleaned up on subsequent scans.

See the [command reference](commands/app-library-scan.md) for details. Metadata enrichment depends on the [External APIs](external-apis.md) configuration.

## File Watching

For continuous monitoring of a library directory, use the `app:watch-files` command. It uses Linux inotify to detect file system events in real time:

```bash
make exec cmd="php bin/console app:watch-files --path /data/music"
```

Watch multiple directories at once:

```bash
make exec cmd="php bin/console app:watch-files --path /data/music --path /data/movies"
```

The watcher detects four event types:

| Event | Meaning |
|-------|---------|
| `CREATE` | A new file or directory was created |
| `MODIFY` | An existing file was changed |
| `DELETE` | A file or directory was removed |
| `MOVE` | A file or directory was renamed or moved |

Adjust the read timeout with `--timeout` (in milliseconds, default `5000`):

```bash
make exec cmd="php bin/console app:watch-files --path /data/music --timeout 10000"
```

If no `--path` is given, the command watches the current working directory.

See the [command reference](commands/app-watch-files.md) for details.

## Supported Media Formats

Baander detects file types by reading magic bytes from the file header, falling back to extension-based detection when headers are ambiguous.

### Audio formats

| Format | Extensions | Notes |
|--------|-----------|-------|
| MP3 | `.mp3` | MPEG-1/2 Audio Layer III, detected via ID3v2 tag or MPEG sync word |
| FLAC | `.flac` | Free Lossless Audio Codec, detected via `fLaC` header |
| OGG Vorbis | `.ogg`, `.oga` | Detected via `OggS` header, codec identified at offset 28 |
| Opus | `.opus` | Detected via `OpusHead` codec identifier inside OGG container |
| WAV | `.wav`, `.wave` | PCM audio, detected via RIFF/WAVE header |
| AAC | `.aac`, `.m4a` | Advanced Audio Coding, detected via `ftyp` container |
| WMA | `.wma` | Windows Media Audio |

### Video formats

| Format | Extensions | Notes |
|--------|-----------|-------|
| MP4 | `.mp4`, `.m4v` | MPEG-4 container, detected via `ftyp` brand at offset 8 |
| Matroska | `.mkv` | Detected via EBML header (`\x1A\x45\xDF\xA3`) |
| WebM | `.webm` | Matroska variant with VP8/VP9/AV1 codecs, same EBML header |
| AVI | `.avi` | Audio Video Interleave, detected via RIFF/AVI header |
| MOV | `.mov` | QuickTime container |
| WMV | `.wmv` | Windows Media Video |

## Cover Art Extraction

After scanning a music library, extract embedded cover art from audio files:

```bash
make exec cmd="php bin/console app:albums:extract-covers"
```

The command queries the database for albums without cover art and dispatches an asynchronous job for each one. Each job reads embedded image data (typically from ID3v2 APIC frames in MP3 files or Vorbis COMMENT blocks in FLAC/OGG files) and stores it as the album cover.

Key points:

- Processing is asynchronous via the message bus. The command returns after dispatching all jobs.
- Albums are processed in batches of 500.
- The command is safe to run repeatedly -- it only targets albums that do not already have a cover.
- If all albums already have cover art, the command exits immediately.

See the [command reference](commands/app-albums-extract-covers.md) for details.

## Next Steps

- [External APIs](external-apis.md) -- configure Discogs, Last.fm, Spotify, MusicBrainz, and TasteDive for metadata enrichment during scans
- [Transcoding](transcoding.md) -- set up on-demand media conversion for streaming and quality tiers
- [CLI Reference](commands/README.md) -- full list of available console commands
