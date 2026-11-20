# External APIs

A library scan reads local files and tags. External services can add identifiers, genres and artist information after those catalog records exist. You can import and play local media without configuring API credentials; a failed lookup is a different problem from a missing source file or failed import.

See [Library Management](library-management.md#what-happens-to-new-music) for automatic requests and [Background Work](../workers/background-work.md) for the workers that process them.

## Supported APIs

| API | Current use | Configuration |
|-----|-------------|---------------|
| MusicBrainz | Matching of albums, songs and artists; artist details; the song sync | No API key. The adapter sends a fixed application User-Agent. |
| Cover Art Archive | The front cover of the MusicBrainz release a match applies | No API key. Requests send the same User-Agent and take turns under the MusicBrainz rate limit. |
| Discogs | Metadata searches that name Discogs as their source | `DISCOGS_TOKEN` |
| Last.fm | Artist search and artist information in metadata browse responses | `LASTFM_API_KEY` |
| Spotify | Adapter exists, but no application service currently calls it | `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET` are not needed for current workflows |
| TasteDive | Adapter exists, but no application service currently calls it | `TASTE_DIVE_API_KEY` is not needed for current workflows |
| LRCLIB | Lyrics fetches for songs without local lyrics | No API key. The client sends a fixed application User-Agent. |

These uses describe the adapters when the process doing the lookup has the
required configuration. No queued work calls Discogs, and the admitted worker
recipe currently cannot receive `DISCOGS_TOKEN`; see the
[configuration limit](#configuration).

### Shared MusicBrainz budget

MusicBrainz allows one request per second from an address. Every MusicBrainz request, whether it comes from a worker or a web request, first waits for a turn under the shared `musicbrainz` [rate limit](../configuration/rate-limits.md#rate-limiting), which keeps all processes together to at most one request per second. Adding workers therefore does not make MusicBrainz lookups faster. Queued enrichment waits for its turn however long that takes.

The metadata search and browse routes first spend the user's own allowance of one call per 10 seconds and answer `429` once it is spent; they then wait at most 10 seconds for a shared turn and answer `503`. Search terms are escaped, so MusicBrainz reads a title such as "AC/DC" or "Re: Foo" as text rather than as query syntax.

### Shared LRCLIB budget and provider pauses

LRCLIB limits the requests it takes from one address. Every LRCLIB request, whether it comes from a worker or a web request, first waits for a turn under the shared `lrclib` [rate limit](../configuration/rate-limits.md#rate-limiting), which keeps all processes together to one request every two seconds. Each request sends the User-Agent `Baander/1.0 (https://baander.app; contact@baander.app)`.

When LRCLIB answers `429`, or `503` with a `Retry-After` header, every process stops sending to LRCLIB for the seconds the header names, one hour at most, or for 5 and then 15 seconds when it names none, and Baander logs a warning with the time the pause ends. The request is sent again after the pause, three sends at most.

A request someone waits on (the fetch of one song, a search or an apply) waits at most 10 seconds for its turn and for a pause to end, and a queued fetch at most 30; a request that cannot be sent within that time is not sent and counts as LRCLIB being unavailable, so its route answers `503` and a bulk run counts the fetch as unavailable. Queued fetches are spaced at least three seconds apart, as [`app:lyrics:fetch`](../commands/app-lyrics-fetch.md) describes.

The lyrics dialog searches when the administrator submits the query, not while typing.

## Degradation Matrix

Use this table when catalog records exist but enrichment is missing.

| Unavailable service | Impact |
|---------------------|--------|
| **MusicBrainz** | Matching and song enrichment have no alternate provider. A request that MusicBrainz answers with `503` is asked again up to twice, 1 and then 3 seconds later and each time on a fresh turn, but a web request asks again only within its 10-second wait. Automatic matching keeps its requests and pauses until MusicBrainz answers; an administrator's search, comparison or apply answers `503`. An artist fill is queued again after 5, 15 and 60 minutes and is then left to the next administrator's metadata sync. Existing local metadata remains available. |
| **Cover Art Archive** | A match that would add a front cover writes nothing. Automatic matching pauses as it does for a MusicBrainz outage; an administrator's apply answers `503`. A cover the Cover Art Archive refuses is no outage: automatic matching applies the match without the cover, and an administrator's apply with the cover ticked answers `409` and writes nothing. |
| **Discogs** | Metadata searches that name Discogs as their source get no results from it. Matching and the song sync do not call Discogs. |
| **Last.fm** | Last.fm artist search and browse information are unavailable. Matching and the song sync do not call Last.fm. |
| **Spotify / TasteDive** | No impact on current application workflows. |
| **LRCLIB** | Remote lyrics fetches cannot supply lyrics; readable `.lrc` sidecars still provide local lyrics during ingestion. A fetch that found LRCLIB unavailable, or could not be sent within its wait, stores nothing, and a later fetch may succeed. |

Provider availability alone does not guarantee a match. Enrichment checks match quality before applying data. A completed sync can therefore leave a record unchanged.

## MusicBrainz Matching

See [MusicBrainz Matching](musicbrainz-matching.md#musicbrainz-matching).

## How Enrichment Works

An administrator can request a metadata sync of every library; see [`app:metadata:sync`](../commands/app-metadata-sync.md). It queues a MusicBrainz match request for each album, as [Automatic matching](musicbrainz-matching.md#automatic-matching) describes, a sync of each song, and the fill of each artist that holds a MusicBrainz ID and misses details, as [Artist details](musicbrainz-matching.md#artist-details) describes. An artist without a MusicBrainz ID gets one from its tags or from a match; see [MusicBrainz Matching](musicbrainz-matching.md#musicbrainz-matching). The `metadata.auto_sync` setting does not disable administrator requests or metadata browsing.

1. A song sync searches MusicBrainz only, and adds the genres of the recording it finds to the song; a song's recording ID comes only from a match.
2. The chosen result must pass the match-quality check.
3. Accepted changes are stored in the catalog and appear on later reads. Last.fm browse responses do not become an automatic third stage of stored enrichment.

Lyrics have a separate `lyrics.auto_fetch` setting, also off by default. It requests LRCLIB lyrics for newly created tracks without an `.lrc` sidecar, and for tracks without lyrics that a rescan gives an artist credit or a new length. The bulk [`app:lyrics:fetch`](../commands/app-lyrics-fetch.md) command can request lyrics for existing tracks independently of that setting. Embedded cover extraction is another separate task; see [Cover Art Extraction](library-management.md#cover-art-extraction).

## Configuration

Put web lookup credentials in the private Compose environment file described in
[Getting Started](../getting-started/installation.md#command-context), rather than committing them
to the checkout. Apply changes using the [configuration lifecycle](../configuration/README.md).
This configures web; it does not configure the independent worker image.

Queued metadata sync runs in workers. Their credentials file accepts a restricted
set of keys that currently excludes `DISCOGS_TOKEN` and `LASTFM_API_KEY`, and the
worker image excludes private environment files. The documented worker recipe
therefore has no supported way to provision these provider secrets. No queued
work calls either provider: Discogs searches run in web, as Last.fm's current
browse requests do. MusicBrainz and LRCLIB do not need these credentials. Do not
bake private tokens into a tracked environment file or image to work around this
limitation.

Server settings such as `metadata.auto_sync` are read during the work that uses them.

## Getting API Keys

For current optional integrations, obtain the credential from its provider:

- **Discogs**: generate a personal access token in the provider's developer settings and set `DISCOGS_TOKEN`.
- **Last.fm**: register an API application and set its API key as `LASTFM_API_KEY`.

MusicBrainz and LRCLIB do not require an API key for these lookups. Spotify and TasteDive credentials do not activate an application workflow in the current implementation.
