# External APIs

A library scan reads local files and tags. External services can add identifiers, genres and artist information after those catalog records exist. You can import and play local media without configuring API credentials; a failed lookup is a different problem from a missing source file or failed import.

See [Library Management](library-management.md#what-happens-to-new-music) for automatic requests and [Background Work](background-work.md) for the workers that process them.

## Supported APIs

| API | Current use | Configuration |
|-----|-------------|---------------|
| MusicBrainz | First source for stored album, artist and song enrichment | No API key. The adapter sends a fixed application User-Agent. |
| Discogs | Album and artist fallback when MusicBrainz returns no search results | `DISCOGS_TOKEN` |
| Last.fm | Artist search and artist information in metadata browse responses | `LASTFM_API_KEY` |
| Spotify | Adapter exists, but no application service currently calls it | `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET` are not needed for current workflows |
| TasteDive | Adapter exists, but no application service currently calls it | `TASTE_DIVE_API_KEY` is not needed for current workflows |
| LRCLIB | Lyrics fetches for songs without local lyrics | No API key |

These uses describe the adapters when the process doing the lookup has the
required configuration. The admitted worker recipe currently cannot receive
`DISCOGS_TOKEN`; see the [configuration limit](#configuration) before relying on
Discogs for queued enrichment.

The template also lists `MUSICBRAINZ_VERSION`, `MUSICBRAINZ_CONTACT` and `LASTFM_API_SECRET`. The current adapters do not consume those values: changing them does not change the MusicBrainz User-Agent or authenticate Last.fm requests. See [Configuration](configuration.md) for the effective deployment settings.

## Degradation Matrix

Use this table when catalog records exist but enrichment is missing.

| Unavailable service | Impact |
|---------------------|--------|
| **MusicBrainz** | Song enrichment has no alternate provider. Album and artist searches can fall back to Discogs when MusicBrainz returns no results; an exception is not a guaranteed fallback. Existing local metadata remains available. |
| **Discogs** | No album or artist fallback results when MusicBrainz finds none. |
| **Last.fm** | Last.fm artist search and browse information are unavailable. Stored album, artist and song syncs do not call Last.fm. |
| **Spotify / TasteDive** | No impact on current application workflows. |
| **LRCLIB** | Remote lyrics fetches cannot supply lyrics; readable `.lrc` sidecars still provide local lyrics during ingestion. |

Provider availability alone does not guarantee a match. Enrichment checks match quality before applying data. A completed sync can therefore leave a record unchanged.

## How Enrichment Works

An administrator can request a metadata sync. With the `metadata.auto_sync` [server setting](configuration.md#server-settings) enabled, ingestion also requests one for each newly created album. The setting is off by default. It controls that automatic album request; it does not disable administrator requests or metadata browsing.

1. Album and artist syncs search MusicBrainz first. If the search returns no results, they search Discogs. Song syncs search MusicBrainz only.
2. The chosen result must pass the match-quality check. Ordinary syncs apply eligible identifiers and metadata according to each field's update rules; force-update requests can replace more existing values. Fields locked by an operator retain their values.
3. Accepted changes are stored in the catalog and appear on later reads. Last.fm browse responses do not become an automatic third stage of stored enrichment.

A rescan of existing music does not request another automatic album sync. Use an administrator metadata sync for existing records, including recovery after an automatic request could not be queued. Follow background jobs and logs in [Monitoring](monitoring.md), then check the affected record: acceptance or job completion alone does not establish a successful provider match.

Lyrics have a separate `lyrics.auto_fetch` setting, also off by default. It requests LRCLIB lyrics for newly created tracks without an `.lrc` sidecar. The bulk [`app:lyrics:fetch`](commands/app-lyrics-fetch.md) command can request lyrics for existing tracks independently of that setting. Embedded cover extraction is another separate task; see [Cover Art Extraction](library-management.md#cover-art-extraction).

## Configuration

Put web lookup credentials in the private Compose environment file described in
[Getting Started](getting-started.md#command-context), rather than committing them
to the checkout. Apply changes using the [configuration lifecycle](configuration.md).
This configures web; it does not configure the independent worker image.

Queued metadata sync runs in workers. Their credentials file accepts a restricted
set of keys that currently excludes `DISCOGS_TOKEN` and `LASTFM_API_KEY`, and the
worker image excludes private environment files. The documented worker recipe
therefore has no supported way to provision these provider secrets. Setting a
Discogs token for web does not enable its fallback in queued sync. MusicBrainz
and LRCLIB do not need these credentials; Last.fm's current browse requests run
in web. Do not bake private tokens into a tracked environment file or image to
work around this limitation.

Server settings such as `metadata.auto_sync` are read during the work that uses them.

## Getting API Keys

For current optional integrations, obtain the credential from its provider:

- **Discogs**: generate a personal access token in the provider's developer settings and set `DISCOGS_TOKEN`.
- **Last.fm**: register an API application and set its API key as `LASTFM_API_KEY`.

MusicBrainz and LRCLIB do not require an API key for these lookups. Spotify and TasteDive credentials do not activate an application workflow in the current implementation.
