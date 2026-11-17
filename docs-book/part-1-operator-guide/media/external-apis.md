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

MusicBrainz allows one request per second from an address. Every MusicBrainz request, whether it comes from a worker or a web request, first waits for a turn under the shared `musicbrainz` [rate limit](../configuration/authentication-and-limits.md#rate-limiting), which keeps all processes together to at most one request per second. Adding workers therefore does not make MusicBrainz lookups faster. Queued enrichment waits for its turn however long that takes. The metadata search and browse routes first spend the user's own allowance of one call per 10 seconds and answer `429` once it is spent; they then wait at most 10 seconds for a shared turn and answer `503`. Search terms are escaped, so MusicBrainz reads a title such as "AC/DC" or "Re: Foo" as text rather than as query syntax.

LRCLIB limits the requests it takes from one address. Every LRCLIB request, whether it comes from a worker or a web request, first waits for a turn under the shared `lrclib` [rate limit](../configuration/authentication-and-limits.md#rate-limiting), which keeps all processes together to one request every two seconds. Each request sends the User-Agent `Baander/1.0 (https://baander.app; contact@baander.app)`. When LRCLIB answers `429`, or `503` with a `Retry-After` header, every process stops sending to LRCLIB for the seconds the header names, one hour at most, or for 5 and then 15 seconds when it names none, and Baander logs a warning with the time the pause ends. The request is sent again after the pause, three sends at most. A request someone waits on (the fetch of one song, a search or an apply) waits at most 10 seconds for its turn and for a pause to end, and a queued fetch at most 30; a request that cannot be sent within that time is not sent and counts as LRCLIB being unavailable, so its route answers `503` and a bulk run counts the fetch as unavailable. Queued fetches are spaced at least three seconds apart, as [`app:lyrics:fetch`](../commands/app-lyrics-fetch.md) describes. The lyrics dialog searches when the administrator submits the query, not while typing.

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

A match links an album, song or artist in the catalog to its MusicBrainz entity: an album to a release, which is one edition of the album, a song to a recording, and an artist to an artist. Applying a match writes MusicBrainz IDs, artist credits and field values into the catalog. It never writes a media file. Albums can be matched automatically in the background; any album, song or artist can be matched by an administrator.

### Matching by hand

In the web app, the **Sync from MusicBrainz** button in the metadata panel of an album, song or artist opens the match dialog. Only administrators see it. The console offers the same steps as [`app:metadata:match:search`](../commands/app-metadata-match-search.md), [`app:metadata:match:compare`](../commands/app-metadata-match-compare.md) and [`app:metadata:match:apply`](../commands/app-metadata-match-apply.md).

1. **Search.** The dialog lists up to five MusicBrainz candidates, the best first, each with a fit score from 0 to 100. Baander computes the score itself from titles and artists and, for an album, from the track count and each track's length, so that it can tell editions of one album apart. When an album's own title finds no release and ends in a comma-separated list in square brackets, as in "Ten Thousand Fists [Reprise Records,9362-49433-2,EU]", Baander searches once more without the list and scores the candidates against the shortened title. The album's stored title stays as it is. When the right candidate is missing, search again with an edited title or artist; a title you type is searched as typed, once.
2. **Compare.** Picking a candidate shows, before anything is written, the current value and the MusicBrainz value of each field the match can set. MusicBrainz IDs, artist credits and empty fields are ticked; a field that holds a different value is not, and a locked field is shown but cannot be ticked. For an album, each local track is paired with a track of the release by disc and position, then by length; unpaired tracks on either side are shown, and only paired tracks are written. Artists that the new credits would merge or remove are listed and ticked; untick one to keep that artist as it is.
3. **Apply.** Baander looks the candidate up again and writes the ticked rows, all of them or none. When the item changed after the comparison, the apply writes nothing; compare again. When the Cover Art Archive refuses the cover of an album whose cover row is ticked, the apply also writes nothing and answers `409` with the reason `cover_refused`; untick the cover to apply the rest.

When a manual album match leaves the album with a release and the album holds a song no match has compared, such as one imported while the match was being applied, the album is queued for automatic matching as after an import: only while the `metadata.auto_sync` setting is on, and due five minutes later. The queue then matches only the songs no match has compared.

An album match sets the album's fields, cover and credits and, on each paired song, the recording ID, title, track and disc numbers, year and credits. The cover is the release's front cover from the Cover Art Archive, at most 1200 pixels wide; a cover that is not a JPEG, PNG or WebP image of at most 10 MB is left out. A song match sets that song's recording ID, title and credits, and never moves the song to another album. An artist match sets the artist's ID, name, sort name, type, country, disambiguation and life span; when another artist already holds the ID, the artist merges into that one instead.

Artist credits follow MusicBrainz's order: the first credited artist becomes primary and the others featured. A song tagged "Post Malone, Luke Combs" that MusicBrainz credits to both artists is therefore credited to Post Malone as primary and to Luke Combs as featured. Credits of other roles, such as a producer's, stay. The song still shows the name it was tagged with, and lyrics lookups still send that name. Each credited artist is found as a scan finds it: the artist holding the MusicBrainz ID, else the oldest artist without an ID whose name is exactly the name MusicBrainz credits, which takes on the ID, else a new artist. An artist that the new credits leave without any credit merges into the artist that takes over most of its primary credits, which receives its favorites, play history and recommendations; an artist that held only featured credits is removed.

An album or song match that creates a credited artist with a MusicBrainz ID, or gives a stored artist its ID, queues the fill of that artist's details once it is applied, whatever the `metadata.auto_sync` setting says; see [Artist details](#artist-details). An artist match queues no fill: its comparison already showed the artist's fields.

### Automatic matching

The `metadata.auto_sync` [server setting](../configuration/server-settings.md#server-settings), labelled **Automatic MusicBrainz matching** in the admin panel, turns on automatic matching of albums. It is off by default, and only super administrators change it. While it is on, an import or rescan queues each album it touches that has no match yet, or that is matched and holds songs no match has compared yet. An [album merge](../commands/app-album-merge.md) queues its target album the same way, because the songs it moved count as not compared yet. Each request waits at least five minutes before it is matched, so that the album's other discs land first.

The queue matches albums one after another, a second apart, so that scans and other background work run in between, and never matches one album in two runs at once. Every request it makes to MusicBrainz waits for its turn under the shared rate limit, so a large library is matched gradually.

For an album without a match, Baander searches MusicBrainz for releases with the album's title and primary artist, looks up the five most promising, and scores them. A title that finds no release is searched once more without a trailing list in square brackets, as in the match dialog. It applies the best release only when that release scores at least 90, leads the next release by at least 10, has as many tracks as the album, and pairs every track within 3 seconds of its length. Editions that score alike and whose tracks agree, in order, on titles, artist credits and lengths count as one release, even when one edition puts on a single disc what another splits over two. An explicit and a clean edition are an example. The match records the edition that keeps the most songs at their own disc and track number, the earliest of those, and writes recording IDs only where the editions agree. A confident match writes MusicBrainz IDs, artist credits with the merges and removals they cause, empty fields and a missing front cover. It never changes a field that holds a value or is locked.

Any other album is marked as needing review, with the reason. Administrators find these albums in the **Matching** tab of **Admin → Library** (`/admin/library`), which opens the same match dialog for each, and through [`app:metadata:match:reviews`](../commands/app-metadata-match-reviews.md). Applying a match by hand takes the album off the list. An album marked for review is queued again only when its number of songs has changed, for example after another disc arrived.

An album match marks each song it compared with the release, whether the song paired with a track or not, and a song match marks its song. Automatic matching does not search again for an album that is already matched: it pairs only the album's unmarked songs, such as those a later disc added, with the stored release. A song that pairs is matched from that release, and one that pairs with no track stays as it is without marking the album for review. Such a song takes a track's recording ID and credits only when its length is within 3 seconds of the track's, so a song without a measured length never pairs. A run for a matched album fills the album's empty fields and a missing cover, but never writes the album's own artist credits, so credits an administrator changed by hand stay as they are. When another match compared one of those songs after the queue read the album, or a song pairs with another track by then, for example after a retag changed its track number or length, the run writes nothing and the album is tried again 15 minutes later. A song imported while a match runs was not compared, so it stays unmarked and is matched on a later run. A request made for an album while the queue is matching it starts no second run: it waits until that run ends, and the album is then matched again from that request's due time.

When MusicBrainz or the Cover Art Archive is unavailable, the request stays queued and nothing is marked for review: the queue pauses for 5, 15 and 60 minutes, then an hour at a time, while the outage lasts. Only an outage pauses the whole queue. Problems with one album hold back only that album while the queue moves on: an album whose apply met a conflicting change to the same album, songs or artists, such as an edit, an import or another match, is tried again after 15 minutes, an album about which MusicBrainz refused a request is tried again after 6 hours, and an album whose match failed with an error is tried again after an hour. A cover the Cover Art Archive refuses, with a client error other than 404, 408 and 429, pauses nothing: the match is applied without the cover, and the log entry of the apply says that the cover was refused.

The queue's runs queue each other. If a run is lost, for example because its message used up its retries, a scheduled job named **Recover the album match queue** starts the queue again: it runs every 10 minutes and queues a run when a request is due and no queue run is in flight. It appears with the other jobs on the admin **Scheduler** page and in [`app:scheduler:list`](../commands/app-scheduler-list.md).

An administrator's metadata sync, including the genre sync, queues the same requests whatever the setting says, and they are due at once. Turning the setting off stops new requests from imports, and the queue drops the requests imports made the next time it reaches one. When ingest cannot record its requests, it logs the error and carries on; a rescan or a library metadata sync requests the albums again. Follow background jobs and logs in [Monitoring](../operations/README.md), then check the affected record: acceptance or job completion alone does not establish a successful provider match.

### Artist details

An artist that holds a MusicBrainz ID takes its missing details from MusicBrainz, looked up by that ID: its sort name, type, country, disambiguation, and the begin and end of its life span. This fill writes only a field that is empty and not locked, and never the artist's name or ID. An artist without an ID is left alone: nothing fills an artist from a search by its name. Biography, gender and Discogs ID are not filled.

Three kinds of work queue the fill:

- An album or song match, by hand or automatic, queues it for each artist the match created with a MusicBrainz ID or gave its ID, once the match is applied, whatever the `metadata.auto_sync` setting says. An artist match queues none.
- An import queues it, while the setting is on, for each artist a batch of files created with an ID from the tags or gave one. An album artist that only an import's album credit creates waits for the next administrator's metadata sync.
- An administrator's metadata sync, from the admin page or [`app:metadata:sync`](../commands/app-metadata-sync.md), queues it for every artist that holds an ID and has one of those six fields empty. Cancelling the sync's artist job ends the fills it queued; see [`app:monitor:job:cancel`](../commands/app-monitor-job-cancel.md).

An artist whose fill is queued is not queued again. The fills fall due two seconds apart, whoever queued them, so the album match queue keeps at least every other turn of the MusicBrainz rate limit. A fill that finds each of the six fields filled or locked asks MusicBrainz nothing. Once MusicBrainz has answered about an artist's ID, with the artist or with no such artist, no fill asks about that ID again for 30 days; an artist given another ID is asked about the new one. To see MusicBrainz's current values for an artist sooner, open the artist's match dialog, or run [`app:metadata:match:compare`](../commands/app-metadata-match-compare.md) and [`app:metadata:match:apply`](../commands/app-metadata-match-apply.md): they compare and write the current values at any time.

A fill writes nothing for an artist that was deleted, merged into another or given another ID while it waited, nor to a field that was filled or locked meanwhile. MusicBrainz itself merges duplicate artists. When it has merged the artist's ID into another artist, the fill writes nothing, keeps the stored ID, logs both IDs, and counts the answer for the 30 days. To identify the artist by the surviving ID, search for the surviving artist in the artist's match dialog and apply it; a candidate whose ID MusicBrainz merged is answered as not found. A request MusicBrainz refuses writes nothing, and the next fill queued for the artist asks again. When MusicBrainz is unavailable, the fill is queued again after 5, 15 and 60 minutes; at the fourth outage in a row it ends, and the next administrator's metadata sync asks again.

## How Enrichment Works

An administrator can request a metadata sync of every library; see [`app:metadata:sync`](../commands/app-metadata-sync.md). It queues a MusicBrainz match request for each album, as [Automatic matching](#automatic-matching) describes, a sync of each song, and the fill of each artist that holds a MusicBrainz ID and misses details, as [Artist details](#artist-details) describes. An artist without a MusicBrainz ID gets one from its tags or from a match; see [MusicBrainz Matching](#musicbrainz-matching). The `metadata.auto_sync` setting does not disable administrator requests or metadata browsing.

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
