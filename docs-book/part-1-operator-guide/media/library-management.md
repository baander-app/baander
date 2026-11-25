# Library Management

To make files available for playback, register their directory as a library, scan it, then let background ingestion build the catalog. Optional enrichment adds information from external services after catalog records exist. A library registration alone does not import its files.

The examples use an installation. On the installation host, run them from its checkout in the shell where the [`dc` function](../getting-started/installation.md#command-context) is defined. Paths such as `/storage/media/music` are paths inside the web and worker containers, which mount the deployment's media volume read-only at `/storage/media`; see [Storage and Data](../getting-started/storage-and-data.md) for the volumes. Console commands require trusted shell access to the installation.

## Creating Libraries

Use the `app:library:create` command to register a new media library. You must provide a name, the absolute path to the media directory, and the library type.

```bash
dc exec web php bin/console app:library:create 'My Music' /storage/media/music music
```

| Argument | Description |
|----------|-------------|
| `name` | Human-readable library name |
| `path` | Absolute path to the media directory on disk |
| `type` | Use `music` or `movie` for the supported scan and ingestion workflows |

The command also accepts `podcast`, `audiobook` and `tv_show` as type values, but their scanners and catalog ingestion are not implemented. Creating one does not provide a working import workflow.

The slug is auto-generated from the name: lowercased, with each run of characters other than `a`–`z` and `0`–`9` replaced by one hyphen and hyphens trimmed from both ends, so "AC/DC" becomes `ac-dc`. Provide `--slug` to override it:

```bash
dc exec web php bin/console app:library:create 'My Music' /storage/media/music music --slug my-music-collection
```

Put the source files in the media volume before creating the library, as [Installation](../getting-started/installation.md#7-add-media-and-scan-a-library) shows. Web must be able to read them for scanning and original-file streaming; the worker must see the same absolute source path for conversion. Registering a library does not copy its source files.

See the [full command reference](../commands/app-library-create.md) for all options including `--sort-order`.

## Scanning

After creating a library, run a scan to index its contents:

```bash
dc exec web php bin/console app:library:scan my-music
```

The console runs discovery inline and records that job in the job monitor. Starting a scan from the administrator interface queues discovery instead: acceptance means it is waiting for background execution, not that any files have been read.

Both routes then follow the same stages:

1. **Discovery** walks the directory, filters recognized extensions, hashes files and updates the file index. It records changed directories for later ingestion. A file symlink is followed only while its target stays inside the library. A link whose target leaves the library is skipped with a warning in the log, and a track or video imported through it earlier is marked unavailable. Directory symlinks are not followed.
2. **Catalog ingestion** consumes those directory messages in the background. Music ingestion reads tags and creates albums and tracks; movie ingestion creates movie and video records.
3. **Optional enrichment** queues MusicBrainz matching of the albums an import or rescan touches, and lyrics work for newly created tracks and for tracks a rescan repairs, when the corresponding setting is enabled.

The scan's file totals and completed library status describe discovery. They do not prove that every queued directory has reached the catalog. Check the resulting albums, tracks or movies separately, and use [Monitoring](../operations/README.md) to investigate pending or failed background work. [Background Work](../workers/background-work.md) explains the delivery stages and worker requirements.

Incremental scans skip unchanged indexed files. A full rescan reprocesses discovery and repairs tracks Baander already has, filling in a missing artist credit and measuring the length again (see [What a rescan repairs](../commands/app-library-scan.md#what-a-rescan-repairs)); it is not a request to erase and rebuild the catalog. A rescan keeps what a [MusicBrainz match](musicbrainz-matching.md#musicbrainz-matching) set. A retag keeps it too, except where the new tags carry a value for a field or credit the artist by MusicBrainz ID; see [Retagged files](../commands/app-library-scan.md#retagged-files). Missing sources retain file-index records marked unavailable. A scan does not promise blanket removal of catalog entries for deleted files. Restore a missing mount before scanning: an unavailable directory can look like an empty library to discovery.

Only one scan of a library runs at a time. Starting a scan claims the library, and while the claim holds, the admin panel and the command refuse a second scan of it. `app:library:scan --all` scans every library that is not already scanning. A running scan renews its claim as it works. A claim that nobody renews for 15 minutes lapses, for example after a scan process was killed. The library then reads as `failed` and can be scanned again. To free a library sooner, release its claim with `app:library:scan my-music --release --force` once you know its scan is gone.

See the [command reference](../commands/app-library-scan.md) for details. The other `app:library:*` commands list, show, update and delete libraries, report their statistics, validate a path and manage library membership; renaming, deleting and scanning in the admin panel need the admin role.

### What happens to new music

After discovery, background ingestion adds new albums and tracks to the catalog. Two [server settings](../configuration/server-settings.md#server-settings), both off by default, add work for the music a scan imports. Baander reads them each time it imports music, so a change applies to the next scan without a restart.

| Setting | While it is on |
|---------|----------------|
| `metadata.auto_sync` | Each album an import or rescan touches is queued for [automatic MusicBrainz matching](musicbrainz-matching.md#automatic-matching) when it has no match yet, or when it is matched and holds songs no match has compared yet, such as those of a new disc. The request waits at least five minutes, so that the album's other discs land first. Each artist a batch of files creates with a MusicBrainz ID from the tags, or gives one, is queued for the [fill of its details](musicbrainz-matching.md#artist-details) from MusicBrainz. |
| `lyrics.auto_fetch` | Each new track without an `.lrc` lyrics file beside it is queued for a lyrics fetch from LRCLIB, once the catalog has stored the track. A track with an `.lrc` file takes its lyrics from that file, when the file lies inside the library and holds at most 1 MiB. A track without lyrics that a rescan gives an artist credit or a new length is queued the same way. |

`metadata.auto_sync` also acts on albums Baander already has, so a rescan with the setting on queues every album it touches that has no match yet; an album left for review is queued again only when its number of songs has changed. `lyrics.auto_fetch` acts on new tracks and on the tracks a rescan repairs; see [What a rescan repairs](../commands/app-library-scan.md#what-a-rescan-repairs). The bulk lyrics fetch, [`app:lyrics:fetch`](../commands/app-lyrics-fetch.md), and the syncs and fetches an administrator starts are not affected by either setting.

### People and credits

Open **People** under Movies to search names and aliases. Each profile shows the
movie, TV, song and album credits from libraries you can access. Open a name in
the credits on a movie, series or episode to reach the same profile. A linked
music Artist also links to their Person profile. **Directors** still opens the
directory of people with directing credits in your accessible movies.

To fill movie cast and crew, run [metadata sync](../commands/app-metadata-sync.md).
To add television credits, register a [series](../commands/app-tv-series-register.md)
in a TV library, then register the [episodes](../commands/app-tv-episode-register.md)
you want in the catalog. Registration imports title metadata and credits. TV
file scanning and playback are separate workflows.

TMDB person IDs keep same-name people distinct. An individual MusicBrainz
Artist can link to the same Person through verified provider IDs. Use
[app:person:manage](../commands/app-person-manage.md) to inspect conflicting
claims, correct profiles or merge confirmed duplicates.

## File Watching

To inspect filesystem changes, use `app:watch-files`. It prints Linux inotify events in the foreground. It does not start scans or update the catalog; run a scan separately when you want to import changes:

```bash
dc exec web php bin/console app:watch-files --path /storage/media/music
```

Each watch covers the named directory, without recursive registration of its subdirectories. Watch multiple directories at once:

```bash
dc exec web php bin/console app:watch-files --path /storage/media/music --path /storage/media/movies
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
dc exec web php bin/console app:watch-files --path /storage/media/music --timeout 10000
```

Always supply at least one `--path`. The current command prints a current-directory message when paths are omitted, but does not register that directory and then fails because no directories are watched.

See the [command reference](../commands/app-watch-files.md) for details.

## Supported Media Formats

Library discovery selects files by extension. FFmpeg then opens a song only as one of the audio containers below, and a video only as one of the video containers, each recognized by the demuxer the table names. These lists describe what a scan discovers and what FFmpeg may open, not a guarantee that every codec inside a container can be decoded or played by a browser. For conversion and browser fallback, see [Transcoding](transcoding.md).

A file that holds another format under a media file name, such as a DASH manifest, an HLS playlist or a concat script named `.mkv`, does not play: those formats name other files, and FFmpeg refuses them.

A scan does not import such a file. The import's failure report names the format FFmpeg found, for example `Not a supported media container: dash.`, and the file's index row is marked unavailable, so a song or video imported from it earlier stops playing.

### Audio formats

| Format | Extensions discovered | FFmpeg demuxer |
|--------|-----------------------|----------------|
| MP3 | `.mp3` | `mp3` |
| FLAC | `.flac` | `flac` |
| Ogg | `.ogg`, `.oga` | `ogg` |
| Opus | `.opus` | `ogg` |
| WAV | `.wav`, `.wave` | `wav` |
| AAC | `.aac` | `aac` |
| MPEG-4 audio | `.m4a`, `.mp4a`, `.m4b` | `mov` |
| WMA | `.wma` | `asf` |
| AIFF | `.aif`, `.aiff`, `.aifc` | `aiff` |

### Video formats

| Format | Extensions discovered | FFmpeg demuxer |
|--------|-----------------------|----------------|
| MP4 | `.mp4`, `.m4v` | `mov` |
| QuickTime | `.mov` | `mov` |
| Matroska | `.mkv` | `matroska` |
| WebM | `.webm` | `matroska` |
| AVI | `.avi` | `avi` |
| WMV | `.wmv` | `asf` |

An AVI file that embeds a text subtitle file loses that subtitle track: FFmpeg reads it with a nested subtitle demuxer that is not in the list. The film still plays.

## Cover Art Extraction

After scanning a music library, extract embedded cover art from audio files:

```bash
dc exec web php bin/console app:album:extract-covers
```

The command runs the coverless-album batch inline and queues one extraction job per album. The independent worker reads embedded images from source audio and stores any extracted cover. A successful batch means the extraction jobs were queued; check album covers and background job outcomes to establish extraction results.

Key points:

- The queue workers extract the covers. The command returns once every job is queued and prints the job ID of its batch.
- Albums are queued in batches of 500.
- A later run selects albums that still lack a cover; it does not overwrite existing covers.

See the [command reference](../commands/app-album-extract-covers.md) for details.

## Next Steps

- [External APIs](external-apis.md) -- understand optional music metadata and lyrics lookups
- [Transcoding](transcoding.md) -- set up on-demand media conversion for streaming and quality tiers
- [CLI Reference](../commands/README.md) -- full list of available console commands
