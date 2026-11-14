# External APIs

A library scan reads local files and tags. External services can add identifiers, genres and artist information after those catalog records exist. You can import and play local media without configuring API credentials; a failed lookup is a different problem from a missing source file or failed import.

See [Library Management](library-management.md#what-happens-to-new-music) for automatic requests and [Background Work](../workers/background-work.md) for the workers that process them.

## Supported APIs

| API | Current use | Configuration |
|-----|-------------|---------------|
| MusicBrainz | Matching of albums, songs and artists; the song and artist syncs | No API key. The adapter sends a fixed application User-Agent. |
| Cover Art Archive | The front cover of the MusicBrainz release a match applies | No API key. Requests send the same User-Agent and take turns under the MusicBrainz rate limit. |
| Discogs | Artist fallback when MusicBrainz returns no search results | `DISCOGS_TOKEN` |
| Last.fm | Artist search and artist information in metadata browse responses | `LASTFM_API_KEY` |
| Spotify | Adapter exists, but no application service currently calls it | `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET` are not needed for current workflows |
| TasteDive | Adapter exists, but no application service currently calls it | `TASTE_DIVE_API_KEY` is not needed for current workflows |
| LRCLIB | Lyrics fetches for songs without local lyrics | No API key |

These uses describe the adapters when the process doing the lookup has the
required configuration. The admitted worker recipe currently cannot receive
`DISCOGS_TOKEN`; see the [configuration limit](#configuration) before relying on
Discogs for queued enrichment.

The template also lists `MUSICBRAINZ_VERSION`, `MUSICBRAINZ_CONTACT` and `LASTFM_API_SECRET`. The current adapters do not consume those values: changing them does not change the MusicBrainz User-Agent or authenticate Last.fm requests. See [Configuration](../configuration/README.md) for the effective deployment settings.

MusicBrainz allows one request per second from an address. Every MusicBrainz request, whether it comes from a worker or a web request, first waits for a turn under the shared `musicbrainz` [rate limit](../configuration/authentication-and-limits.md#rate-limiting), which keeps all processes together to at most one request per second. Adding workers therefore does not make MusicBrainz lookups faster. Queued enrichment waits for its turn however long that takes; the metadata search and browse routes wait at most 10 seconds and then answer `503`. Search terms are escaped, so MusicBrainz reads a title such as "AC/DC" or "Re: Foo" as text rather than as query syntax.

## Degradation Matrix

Use this table when catalog records exist but enrichment is missing.

| Unavailable service | Impact |
|---------------------|--------|
| **MusicBrainz** | Matching and song enrichment have no alternate provider. A request that MusicBrainz answers with `503` is asked again up to twice, 1 and then 3 seconds later and each time on a fresh turn, but a web request asks again only within its 10-second wait. Automatic matching keeps its requests and pauses until MusicBrainz answers; an administrator's search, comparison or apply answers `503`. Artist searches can fall back to Discogs when MusicBrainz returns no results; an exception is not a guaranteed fallback. Existing local metadata remains available. |
| **Cover Art Archive** | A match that would add a front cover writes nothing. Automatic matching pauses as it does for a MusicBrainz outage, also when the Cover Art Archive refuses the request; an administrator's apply answers `503`. |
| **Discogs** | No artist fallback results when MusicBrainz finds none. |
| **Last.fm** | Last.fm artist search and browse information are unavailable. Matching and the stored artist and song syncs do not call Last.fm. |
| **Spotify / TasteDive** | No impact on current application workflows. |
| **LRCLIB** | Remote lyrics fetches cannot supply lyrics; readable `.lrc` sidecars still provide local lyrics during ingestion. |

Provider availability alone does not guarantee a match. Enrichment checks match quality before applying data. A completed sync can therefore leave a record unchanged.

## MusicBrainz Matching

A match links an album, song or artist in the catalog to its MusicBrainz entity: an album to a release, which is one edition of the album, a song to a recording, and an artist to an artist. Applying a match writes MusicBrainz IDs, artist credits and field values into the catalog. It never writes a media file. Albums can be matched automatically in the background; any album, song or artist can be matched by an administrator.

### Matching by hand

In the web app, the **Sync from MusicBrainz** button in the metadata panel of an album, song or artist opens the match dialog. Only administrators see it. The console offers the same steps as [`app:metadata:match:search`](../commands/app-metadata-match-search.md), [`app:metadata:match:compare`](../commands/app-metadata-match-compare.md) and [`app:metadata:match:apply`](../commands/app-metadata-match-apply.md).

1. **Search.** The dialog lists up to five MusicBrainz candidates, the best first, each with a fit score from 0 to 100. Baander computes the score itself from titles and artists and, for an album, from the track count and each track's length, so that it can tell editions of one album apart. When the right candidate is missing, search again with an edited title or artist.
2. **Compare.** Picking a candidate shows, before anything is written, the current value and the MusicBrainz value of each field the match can set. MusicBrainz IDs, artist credits and empty fields are ticked; a field that holds a different value is not, and a locked field is shown but cannot be ticked. For an album, each local track is paired with a track of the release by disc and position, then by length; unpaired tracks on either side are shown, and only paired tracks are written. Artists that the new credits would merge or remove are listed and ticked; untick one to keep that artist as it is.
3. **Apply.** Baander looks the candidate up again and writes the ticked rows, all of them or none. When the item changed after the comparison, the apply writes nothing; compare again.

An album match sets the album's fields, cover and credits and, on each paired song, the recording ID, title, track and disc numbers, year and credits. The cover is the release's front cover from the Cover Art Archive, at most 1200 pixels wide; a cover that is not a JPEG, PNG or WebP image of at most 10 MB is left out. A song match sets that song's recording ID, title and credits, and never moves the song to another album. An artist match sets the artist's ID, name, sort name, type, country, disambiguation and life span; when another artist already holds the ID, the artist merges into that one instead.

Artist credits follow MusicBrainz's order: the first credited artist becomes primary and the others featured. A song tagged "Post Malone, Luke Combs" that MusicBrainz credits to both artists is therefore credited to Post Malone as primary and to Luke Combs as featured. Credits of other roles, such as a producer's, stay. The song still shows the name it was tagged with, and lyrics lookups still send that name. Each credited artist is found as a scan finds it: the artist holding the MusicBrainz ID, else the oldest artist without an ID whose name is exactly the name MusicBrainz credits, which takes on the ID, else a new artist. An artist that the new credits leave without any credit merges into the artist that takes over most of its primary credits, which receives its favorites, play history and recommendations; an artist that held only featured credits is removed.

### Automatic matching

The `metadata.auto_sync` [server setting](../configuration/server-settings.md#server-settings), labelled **Automatic MusicBrainz matching** in the admin panel, turns on automatic matching of albums. It is off by default, and only super administrators change it. While it is on, an import or rescan queues each album it touches that has no match yet, or that is matched and holds songs no match has compared yet. Each request waits at least five minutes before it is matched, so that the album's other discs land first.

The queue matches albums one after another, a second apart, so that scans and other background work run in between, and never matches one album in two runs at once. Every request it makes to MusicBrainz waits for its turn under the shared rate limit, so a large library is matched gradually.

For an album without a match, Baander searches MusicBrainz for releases with the album's title and primary artist, looks up the five most promising, and scores them. It applies the best release only when that release scores at least 90, leads the next release by at least 10, has as many tracks as the album, and pairs every track within 3 seconds of its length. Editions that score alike and whose tracks agree on titles, artist credits and lengths, such as an explicit and a clean edition, count as one release: the match records the earliest of them and writes recording IDs only where the editions agree. A confident match writes MusicBrainz IDs, artist credits with the merges and removals they cause, empty fields and a missing front cover. It never changes a field that holds a value or is locked.

Any other album is marked as needing review, with the reason. Administrators find these albums in the **Matching** tab of **Admin → Library** (`/admin/library`), which opens the same match dialog for each, and through [`app:metadata:match:reviews`](../commands/app-metadata-match-reviews.md). Applying a match by hand takes the album off the list. An album marked for review is queued again only when its number of songs has changed, for example after another disc arrived.

An album match marks each song it compared with the release, whether the song paired with a track or not, and a song match marks its song. Automatic matching does not search again for an album that is already matched: it pairs only the album's unmarked songs, such as those a later disc added, with the stored release. A song that pairs is matched from that release, and one that pairs with no track stays as it is without marking the album for review. A song imported while a match runs was not compared, so it stays unmarked and is matched on a later run.

When MusicBrainz or the Cover Art Archive is unavailable, the request stays queued and nothing is marked for review: the queue pauses for 5, 15 and 60 minutes, then an hour at a time, while the outage lasts. Only an outage pauses the whole queue. Problems with one album hold back only that album while the queue moves on: an album whose apply met a conflicting change to the same album, songs or artists, such as an edit, an import or another match, is tried again after 15 minutes, and an album about which MusicBrainz refused a request is tried again after 6 hours. A Cover Art Archive refusal counts as an outage.

The queue's runs queue each other. If a run is lost, for example because its message used up its retries, a scheduled job named **Recover the album match queue** starts the queue again: it runs every 10 minutes and queues a run when a request is due and no queue run is in flight. It appears with the other jobs on the admin **Scheduler** page and in [`app:scheduler:list`](../commands/app-scheduler-list.md).

An administrator's metadata sync, including the genre sync, queues the same requests whatever the setting says, and they are due at once. Turning the setting off stops new requests from imports, and the queue drops the requests imports made the next time it reaches one. When ingest cannot record its requests, it logs the error and carries on; a rescan or a library metadata sync requests the albums again. Follow background jobs and logs in [Monitoring](../operations/README.md), then check the affected record: acceptance or job completion alone does not establish a successful provider match.

## How Enrichment Works

An administrator can request a metadata sync of every library; see [`app:metadata:sync`](../commands/app-metadata-sync.md). It queues a MusicBrainz match request for each album, as [Automatic matching](#automatic-matching) describes, and a sync of each song. The `metadata.auto_sync` setting does not disable administrator requests or metadata browsing.

1. A song sync searches MusicBrainz only, and adds the genres of the recording it finds to the song; a song's recording ID comes only from a match. An artist sync searches MusicBrainz first and, when that search returns no results, Discogs.
2. The chosen result must pass the match-quality check. An ordinary artist sync applies eligible identifiers and metadata according to each field's update rules; a force-update request can replace more existing values. An artist's MusicBrainz ID is never replaced, even by a force-update request, and a search result supplies one only when its name matches the artist's name, ignoring case, and no other artist holds that ID. Fields locked by an operator retain their values.
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
