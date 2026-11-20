# Match catalog records with MusicBrainz

Use manual matching to inspect proposed changes before applying them, or configure the album queue for automatic matching. Provider setup, shared request budgets and outage behavior are in [External APIs](external-apis.md).

## MusicBrainz Matching

A match links an album, song or artist in the catalog to its MusicBrainz entity: an album to a release, which is one edition of the album, a song to a recording, and an artist to an artist. Applying a match writes MusicBrainz IDs, artist credits and field values into the catalog. It never writes a media file. Albums can be matched automatically in the background; any album, song or artist can be matched by an administrator.

### Matching by hand

In the web app, the **Sync from MusicBrainz** button in the metadata panel of an album, song or artist opens the match dialog. Only administrators see it. The console offers the same steps as [`app:metadata:match:search`](../commands/app-metadata-match-search.md), [`app:metadata:match:compare`](../commands/app-metadata-match-compare.md) and [`app:metadata:match:apply`](../commands/app-metadata-match-apply.md).

1. **Search.** The dialog lists up to five MusicBrainz candidates, the best first, each with a fit score from 0 to 100. Baander computes the score itself from titles and artists and, for an album, from the track count and each track's length, so that it can tell editions of one album apart.

   When an album's own title finds no release and ends in a comma-separated list in square brackets, as in "Ten Thousand Fists [Reprise Records,9362-49433-2,EU]", Baander searches once more without the list and scores the candidates against the shortened title. The album's stored title stays as it is. When the right candidate is missing, search again with an edited title or artist; a title you type is searched as typed, once.

2. **Compare.** Picking a candidate shows, before anything is written, the current value and the MusicBrainz value of each field the match can set. MusicBrainz IDs, artist credits and empty fields are ticked; a field that holds a different value is not, and a locked field is shown but cannot be ticked.

   For an album, each local track is paired with a track of the release by disc and position, then by length; unpaired tracks on either side are shown, and only paired tracks are written. Artists that the new credits would merge or remove are listed and ticked; untick one to keep that artist as it is.

3. **Apply.** Baander looks the candidate up again and writes the ticked rows, all of them or none. When the item changed after the comparison, the apply writes nothing; compare again.

   When the Cover Art Archive refuses the cover of an album whose cover row is ticked, the apply also writes nothing and answers `409` with the reason `cover_refused`; untick the cover to apply the rest.

When a manual album match leaves the album with a release and the album holds a song no match has compared, such as one imported while the match was being applied, the album is queued for automatic matching as after an import: only while the `metadata.auto_sync` setting is on, and due five minutes later. The queue then matches only the songs no match has compared.

An album match sets the album's fields, cover and credits and, on each paired song, the recording ID, title, track and disc numbers, year and credits. The cover is the release's front cover from the Cover Art Archive, at most 1200 pixels wide; a cover that is not a JPEG, PNG or WebP image of at most 10 MB is left out. A song match sets that song's recording ID, title and credits, and never moves the song to another album.

An artist match sets the artist's ID, name, sort name, type, country, disambiguation and life span; when another artist already holds the ID, the artist merges into that one instead.

Artist credits follow MusicBrainz's order: the first credited artist becomes primary and the others featured. A song tagged "Post Malone, Luke Combs" that MusicBrainz credits to both artists is therefore credited to Post Malone as primary and to Luke Combs as featured. Credits of other roles, such as a producer's, stay. The song still shows the name it was tagged with, and lyrics lookups still send that name.

Each credited artist is found as a scan finds it: the artist holding the MusicBrainz ID, else the oldest artist without an ID whose name is exactly the name MusicBrainz credits, which takes on the ID, else a new artist. An artist that the new credits leave without any credit merges into the artist that takes over most of its primary credits, which receives its favorites, play history and recommendations; an artist that held only featured credits is removed.

An album or song match that creates a credited artist with a MusicBrainz ID, or gives a stored artist its ID, queues the fill of that artist's details once it is applied, whatever the `metadata.auto_sync` setting says; see [Artist details](#artist-details). An artist match queues no fill: its comparison already showed the artist's fields.

### Automatic matching

The `metadata.auto_sync` [server setting](../configuration/server-settings.md#server-settings), labelled **Automatic MusicBrainz matching** in the admin panel, turns on automatic matching of albums. It is off by default, and only super administrators change it. While it is on, an import or rescan queues each album it touches that has no match yet, or that is matched and holds songs no match has compared yet. An [album merge](../commands/app-album-merge.md) queues its target album the same way, because the songs it moved count as not compared yet.

Each request waits at least five minutes before it is matched, so that the album's other discs land first.

The queue matches albums one after another, a second apart, so that scans and other background work run in between, and never matches one album in two runs at once. Every request it makes to MusicBrainz waits for its turn under the shared rate limit, so a large library is matched gradually.

#### Choosing a release for an unmatched album

For an unmatched album, Baander searches MusicBrainz by title and primary artist. It looks up and scores the five most promising releases. A title that finds no release is searched once more without a trailing list in square brackets, as in the match dialog. It applies the best release only when that release scores at least 90, leads the next release by at least 10, has as many tracks as the album, and pairs every track within 3 seconds of its length.

Editions that score alike and whose tracks agree, in order, on titles, artist credits and lengths count as one release, even when one edition puts on a single disc what another splits over two. An explicit and a clean edition are an example. The match records the edition that keeps the most songs at their own disc and track number, the earliest of those, and writes recording IDs only where the editions agree.

A confident match writes MusicBrainz IDs, artist credits with the merges and removals they cause, empty fields and a missing front cover. It never changes a field that holds a value or is locked.

Any other album is marked as needing review, with the reason. Administrators find these albums in the **Matching** tab of **Admin → Library** (`/admin/library`), which opens the same match dialog for each, and through [`app:metadata:match:reviews`](../commands/app-metadata-match-reviews.md). Applying a match by hand takes the album off the list. An album marked for review is queued again only when its number of songs has changed, for example after another disc arrived.

#### Adding songs to an already matched album

An album match marks every song it compared with the release, including songs that paired with no track. A song match marks its song. Automatic matching does not search again for an album that is already matched: it pairs only the album's unmarked songs, such as those a later disc added, with the stored release. A song that pairs is matched from that release, and one that pairs with no track stays as it is without marking the album for review.

Such a song takes a track's recording ID and credits only when its length is within 3 seconds of the track's, so a song without a measured length never pairs. A run for a matched album fills the album's empty fields and a missing cover, but never writes the album's own artist credits, so credits an administrator changed by hand stay as they are.

When another match compared one of those songs after the queue read the album, or a song pairs with another track by then, for example after a retag changed its track number or length, the run writes nothing and the album is tried again 15 minutes later. A song imported while a match runs was not compared, so it stays unmarked and is matched on a later run.

A request made for an album while the queue is matching it starts no second run: it waits until that run ends, and the album is then matched again from that request's due time.

#### Outages, conflicts and queue recovery

When MusicBrainz or the Cover Art Archive is unavailable, the request stays queued; the album is not marked for review. The queue pauses for 5, 15 and 60 minutes, then an hour at a time while the outage lasts. Only an outage pauses the whole queue.

Problems with one album hold back only that album while the queue moves on: an album whose apply met a conflicting change to the same album, songs or artists, such as an edit, an import or another match, is tried again after 15 minutes, an album about which MusicBrainz refused a request is tried again after 6 hours, and an album whose match failed with an error is tried again after an hour.

A cover the Cover Art Archive refuses, with a client error other than 404, 408 and 429, pauses nothing: the match is applied without the cover, and the log entry of the apply says that the cover was refused.

The queue's runs queue each other. If a run is lost, for example because its message used up its retries, a scheduled job named **Recover the album match queue** starts the queue again: it runs every 10 minutes and queues a run when a request is due and no queue run is in flight. It appears with the other jobs on the admin **Scheduler** page and in [`app:scheduler:list`](../commands/app-scheduler-list.md).

An administrator's metadata sync, including the genre sync, queues the same requests whatever the setting says, and they are due at once. Turning the setting off stops new requests from imports, and the queue drops the requests imports made the next time it reaches one. When ingest cannot record its requests, it logs the error and carries on; a rescan or a library metadata sync requests the albums again.

Follow background jobs and logs in [Monitoring](../operations/README.md), then check the affected record: acceptance or job completion alone does not establish a successful provider match.

### Artist details

An artist that holds a MusicBrainz ID takes its missing details from MusicBrainz, looked up by that ID: its sort name, type, country, disambiguation, and the begin and end of its life span. This fill writes only a field that is empty and not locked, and never the artist's name or ID. An artist without an ID is left alone: nothing fills an artist from a search by its name. Biography, gender and Discogs ID are not filled.

Three kinds of work queue the fill:

- An album or song match, by hand or automatic, queues it for each artist the match created with a MusicBrainz ID or gave its ID, once the match is applied, whatever the `metadata.auto_sync` setting says. An artist match queues none.
- An import queues it, while the setting is on, for each artist a batch of files created with an ID from the tags or gave one. An album artist that only an import's album credit creates waits for the next administrator's metadata sync.
- An administrator's metadata sync, from the admin page or [`app:metadata:sync`](../commands/app-metadata-sync.md), queues it for every artist that holds an ID and has one of those six fields empty. Cancelling the sync's artist job ends the fills it queued; see [`app:monitor:job:cancel`](../commands/app-monitor-job-cancel.md).

#### Scheduling and refreshing artist details

An artist with a queued fill does not get another queued fill. The fills fall due two seconds apart, whoever queued them, so the album match queue keeps at least every other turn of the MusicBrainz rate limit. A fill that finds each of the six fields filled or locked asks MusicBrainz nothing.

Once MusicBrainz has answered about an artist's ID, with the artist or with no such artist, no fill asks about that ID again for 30 days; an artist given another ID is asked about the new one. To see MusicBrainz's current values for an artist sooner, open the artist's match dialog, or run [`app:metadata:match:compare`](../commands/app-metadata-match-compare.md) and [`app:metadata:match:apply`](../commands/app-metadata-match-apply.md): they compare and write the current values at any time.

#### Changed artists and provider failures

A waiting fill writes nothing if the artist was deleted, merged or given another ID. It also leaves fields alone if they were filled or locked while it waited. MusicBrainz itself merges duplicate artists. When it has merged the artist's ID into another artist, the fill writes nothing, keeps the stored ID, logs both IDs, and counts the answer for the 30 days.

To identify the artist by the surviving ID, search for the surviving artist in the artist's match dialog and apply it; a candidate whose ID MusicBrainz merged is answered as not found. A request MusicBrainz refuses writes nothing, and the next fill queued for the artist asks again. When MusicBrainz is unavailable, the fill is queued again after 5, 15 and 60 minutes; at the fourth outage in a row it ends, and the next administrator's metadata sync asks again.
