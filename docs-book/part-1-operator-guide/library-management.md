# Library Management

To make files available for playback, register their directory as a library, scan it, then let background ingestion build the catalog. Optional enrichment adds information from external services after catalog records exist. A library registration alone does not import its files.

The examples use the installed stack. On the installation host, run them from its checkout in the shell where the [installed `dc` wrapper](getting-started.md#command-context) is defined. Paths such as `/media/library/music` are paths inside the app and worker containers; see [Storage and Data](storage-and-data.md) for their host mounts. Console commands require trusted shell access to the installation.

## Creating Libraries

Use the `app:library:create` command to register a new media library. You must provide a name, the absolute path to the media directory, and the library type.

```bash
dc exec app php bin/console app:library:create 'My Music' /media/library/music music
```

| Argument | Description |
|----------|-------------|
| `name` | Human-readable library name |
| `path` | Absolute path to the media directory on disk |
| `type` | Use `music` or `movie` for the supported scan and ingestion workflows |

The command also accepts `podcast`, `audiobook` and `tv_show` as type values, but their scanners and catalog ingestion are not implemented. Creating one does not provide a working import workflow.

The slug is auto-generated from the name (lowercased, spaces replaced with hyphens, special characters removed). Provide `--slug` to override it:

```bash
dc exec app php bin/console app:library:create 'My Music' /media/library/music music --slug my-music-collection
```

Mount the source directory before creating the library. The app must be able to read it for scanning and original-file streaming; the worker must see the same absolute source path for conversion. Registering a library does not copy its source files.

See the [full command reference](commands/app-library-create.md) for all options including `--sort-order`.

## Scanning

After creating a library, run a scan to index its contents:

```bash
dc exec app php bin/console app:library:scan my-music
```

The console runs discovery inline and records that job in the job monitor. Starting a scan from the administrator interface queues discovery instead: acceptance means it is waiting for background execution, not that any files have been read.

Both routes then follow the same stages:

1. **Discovery** walks the directory, filters recognized extensions, hashes files and updates the file index. It records changed directories for later ingestion.
2. **Catalog ingestion** consumes those directory messages in the background. Music ingestion reads tags and creates albums and tracks; movie ingestion creates movie and video records.
3. **Optional enrichment** queues metadata or lyrics work for newly created music records when the corresponding setting is enabled.

The scan's file totals and completed library status describe discovery. They do not prove that every queued directory has reached the catalog. Check the resulting albums, tracks or movies separately, and use [Monitoring](monitoring.md) to investigate pending or failed background work. [Background Work](background-work.md) explains the delivery stages and worker requirements.

Incremental scans skip unchanged indexed files. A full rescan reprocesses discovery; it is not a request to erase and rebuild the catalog. Missing sources retain file-index records marked unavailable. A scan does not promise blanket removal of catalog entries for deleted files. Restore a missing mount before scanning: an unavailable directory can look like an empty library to discovery.

Only one scan of a library runs at a time. Starting a scan claims the library, and while the claim holds, the admin panel and the command refuse a second scan of it. `app:library:scan --all` scans every library that is not already scanning. A running scan renews its claim as it works. A claim that nobody renews for 15 minutes lapses, for example after a scan process was killed. The library then reads as `failed` and can be scanned again. To free a library sooner, release its claim with `app:library:scan my-music --release --force` once you know its scan is gone.

See the [command reference](commands/app-library-scan.md) for details. The other `app:library:*` commands list, show, rename and delete libraries; renaming, deleting and scanning in the admin panel need the admin role.

### What happens to new music

After discovery, background ingestion adds new albums and tracks to the catalog. Two [server settings](configuration.md#server-settings), both off by default, add work for each new item. Baander reads them each time it adds an album or tracks, so a change applies to the next scan without a restart.

| Setting | While it is on |
|---------|----------------|
| `metadata.auto_sync` | Each new album is queued for a metadata sync from the [external services](external-apis.md), right after the catalog stores it. |
| `lyrics.auto_fetch` | Each new track without an `.lrc` lyrics file beside it is queued for a lyrics fetch from LRCLIB, once the catalog has stored the track. A track with an `.lrc` file takes its lyrics from that file. |

Both act only on albums and tracks that a scan creates. A rescan of music Baander already has does not request these automatic metadata or lyrics fetches. The bulk lyrics fetch, [`app:lyrics:fetch`](commands/app-lyrics-fetch.md), and the syncs and fetches an administrator starts are not affected by either setting.

## File Watching

To inspect filesystem changes, use `app:watch-files`. It prints Linux inotify events in the foreground. It does not start scans or update the catalog; run a scan separately when you want to import changes:

```bash
dc exec app php bin/console app:watch-files --path /media/library/music
```

Each watch covers the named directory, without recursive registration of its subdirectories. Watch multiple directories at once:

```bash
dc exec app php bin/console app:watch-files --path /media/library/music --path /media/library/movies
```

The watcher prints these event categories:

| Event | Meaning |
|-------|---------|
| `CREATE` | A new file or directory was created |
| `MODIFY` | An existing file was changed |
| `DELETE` | A file or directory was removed |
| `MOVE` | A file or directory was renamed or moved |
| `CLOSE_WRITE` | A file opened for writing was closed |

Adjust the read timeout with `--timeout` (in milliseconds, default `5000`):

```bash
dc exec app php bin/console app:watch-files --path /media/library/music --timeout 10000
```

Always supply at least one `--path`. The current command prints a current-directory message when paths are omitted, but does not register that directory and then fails because no directories are watched.

See the [command reference](commands/app-watch-files.md) for details.

## Supported Media Formats

Library discovery selects files by extension. These lists describe what a scan discovers, not a guarantee that every codec inside a container can be decoded or played by a browser. For conversion and browser fallback, see [Transcoding](transcoding.md).

### Audio formats

| Format | Extensions discovered |
|--------|-----------------------|
| MP3 | `.mp3` |
| FLAC | `.flac` |
| OGG | `.ogg` |
| Opus | `.opus` |
| WAV | `.wav` |
| AAC / MPEG-4 audio | `.aac`, `.m4a` |
| WMA | `.wma` |

The scanner does not include `.oga` or `.wave`, even where a metadata reader could recognize their contents.

### Video formats

| Format | Extensions discovered |
|--------|-----------------------|
| MP4 | `.mp4`, `.m4v` |
| Matroska | `.mkv` |
| WebM | `.webm` |
| AVI | `.avi` |
| MOV | `.mov` |
| WMV | `.wmv` |

## Cover Art Extraction

After scanning a music library, extract embedded cover art from audio files:

```bash
dc exec app php bin/console app:album:extract-covers
```

The command runs the coverless-album batch inline and queues one extraction job per album. The independent worker reads embedded images from source audio and stores any extracted cover. A successful batch means the extraction jobs were queued; check album covers and background job outcomes to establish extraction results.

Key points:

- The queue workers extract the covers. The command returns once every job is queued and prints the job ID of its batch.
- Albums are queued in batches of 500.
- A later run selects albums that still lack a cover; it does not overwrite existing covers.

See the [command reference](commands/app-album-extract-covers.md) for details.

## Next Steps

- [External APIs](external-apis.md) -- understand optional music metadata and lyrics lookups
- [Transcoding](transcoding.md) -- set up on-demand media conversion for streaming and quality tiers
- [CLI Reference](commands/README.md) -- full list of available console commands
