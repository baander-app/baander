# Catalog MusicBrainz Matching

A match writes catalog data, so Catalog owns comparison, application and the queues that request it. This page follows candidates through those stages and then describes artist synchronization. [Catalog](catalog.md) covers ordinary edits and merges.

## MusicBrainz Matching

Catalog matches albums, songs and artists with MusicBrainz, because every write a match makes is a Catalog write. It reaches MusicBrainz and the Cover Art Archive only through Metadata's `MusicBrainzLookupInterface`, whose calls each wait for a turn under the shared `musicbrainz` rate limit. An album matches a release, one edition, and the release's group fills `albums.mbid`; a song matches a recording and an artist an artist. [External APIs](../../part-1-operator-guide/media/musicbrainz-matching.md#musicbrainz-matching) describes matching for operators.

### Candidates and comparison

`SearchMatchCandidatesHandler` reads the item before asking MusicBrainz, so an unknown item spends no request. It searches according to the item:

- An album searches releases by title and primary artist, ranks the hits by title, artist and track count, and looks up the best five with their tracks.
- A song searches recordings once, using its title and the artist text it shows.
- An artist searches artists once, using its name.

`AlbumReleaseSearch` retries an album's own title only when the first search finds no release and the title ends in a comma-separated list in square brackets, such as "Ten Thousand Fists [Reprise Records,9362-49433-2,EU]". MusicBrainz matches the searched title as a whole phrase. `ReleaseSearchTitle` therefore removes that list for the second search but keeps other bracketed text, which can name another release. The answer names the title that found the candidates, and scoring uses that title. The stored title never changes. A title the admin typed is searched as typed, once.

`CandidateScorer` folds case, punctuation and featuring joiners, then scores each candidate from 0 to 100:

- A looked-up release: title 25, artist 20, track count 20 and track lengths 35.
- A recording: title 45, artist 35 and length 20.
- An artist: its name, or name 80 and disambiguation 20.

`TrackPairing` pairs local and release tracks by disc and position, then by a length within 3 seconds that fits only one track.

`MusicBrainzAnswer` returns an outage as 503 with reason `musicbrainz_unavailable`, and a refused request as 409 with `musicbrainz_rejected`. Candidate search, comparison and apply distinguish their 404 answers through `details.reason`: `item_not_found` means the item is unknown or deleted; `candidate_not_found` means MusicBrainz has no candidate with the ID named by the comparison or apply.

`CompareMatchHandler` looks the candidate up once and builds a `MatchComparison` with `MatchComparisonBuilder`, which reads the catalog through `MatchCatalogReadInterface` and changes nothing. The comparison holds three kinds of rows:

- Field rows (`MatchFieldRow`): MusicBrainz IDs and empty fields are ticked, a field holding a different value is not, and a locked field is shown but never ticked.
- Credits rows (`MatchCreditsRow`), ticked when they change something: MusicBrainz's first credited artist becomes primary and the others featured. The primary credit keeps the song's or album's own credited name, and a featured credit takes the name MusicBrainz credits. Only primary and featured credits are replaced; credits of other roles stay. Each credited artist resolves as an import resolves it, so a row names the local artist, or the new one, each credit would go to.
- Artist actions (`MatchArtistAction`), ticked: an artist the ticked credits rows leave without any credit merges into the artist that takes over most of its primary credits, or is removed when it held none. An artist match whose ID another artist holds becomes a merge into that holder.

### Apply

`ApplyMatchHandler` handles `ApplyMatchCommand` and writes a match in one transaction, or nothing. Every MusicBrainz call runs before the transaction: a manual apply looks the candidate up again by its ID and compares it with the item as stored, never with values the client sent, and a front cover the match sets is downloaded and stored as a file (`MatchCovers`). When the Cover Art Archive refuses the cover, with a client error other than 404, 408 and 429, a manual apply that ticked it answers 409 `cover_refused` before its transaction and writes nothing.

The transaction then takes its locks in the order every catalog writer takes them: the album row `FOR NO KEY UPDATE`; every artist and genre import key in one sorted pass; the rows of the artists the match may credit, merge, merge into or remove, `FOR UPDATE` through `ArtistMergePortInterface::lock()`; the rows of the songs it writes, `FOR NO KEY UPDATE` in ID order. Under those locks it reads the item again and builds the comparison once more.

A manual apply whose ticked rows no longer show what the admin saw, or whose comparison now needs a lock it did not take, writes nothing and answers 409 `match_stale`; a deadlock answers 409 `match_busy`.

#### Recording the applied match

The apply writes fields, IDs and the credits that change, records on each song the fields a match set in `songs.matched_fields` (`title`, `track`, `disc`, `year`, and `credits` when it set the credits), and records an album's release in `albums.release_mbid` with the time in `albums.matched_at`. It sets `songs.match_checked_at` on every song the candidate was compared with, paired with a track or not, under the song row locks it took for them; a song apply sets it on its song.

The column is bookkeeping, which the live-update capture does not watch. The apply then merges or removes the artists the new credits left without any. A manual album apply also deletes the album's match state, which takes it off the review list. Covers that merges, removals or a replaced cover free are discarded after the commit. A manual album apply that leaves the album with a release then calls `AlbumMatchRequestInterface::requestAfterImport()`: a song an import committed after the apply read the album was not compared, and the request that import made went with the match state.

The call requests only while `metadata.auto_sync` is on and only when a song of the album has a null `match_checked_at`; a failure is logged and the apply stands. An album left without a release is not requested, so a search does not match over the admin's choice. Last, an album or song apply passes the artists its resolver call created with a MusicBrainz ID or gave one to `ArtistSyncRequestInterface::requestAfterMatch()`, which queues their [artist sync](#artist-sync) whatever the setting says; an artist apply queues none.

#### Automatic application

`ApplyMatchCommand::automatic()` carries the candidate the album match queue decided on. An automatic apply writes no admin ticks: it fills every MusicBrainz ID, field and cover that is empty and unlocked, writes every credits row that changes, and applies the merges and removals those credits cause. An apply to an album that held the release when the matcher read it (`releaseIdSeen`) matches the album's new songs and leaves the album's own credits row out (`MatchTicks::automatic()` with `ofMatchedAlbum`), and with it every merge or removal that needs that row, so credits an admin kept or changed by hand stay; it still fills the album's empty fields and a missing cover.

Under its locks it also checks the songs the candidate compared: when one carries `match_checked_at` by then, because another match compared it, or when the songs as read under the locks pair with the release differently within the length tolerance (`TrackPairs::withinLengthTolerance()`), for example after a retag, it writes nothing and answers stale, and the queue retries the album after 15 minutes. A cover the Cover Art Archive refuses does not stop it: the match is applied without the cover, and its result and its log entry say that the cover was refused.

It reports to the queue how it ended.

#### Artist merges and removal

`ArtistMergePortInterface`, implemented by `ArtistMergeService`, runs inside the caller's transaction after the caller took the import keys and called `lock()`. A merge moves the source artist's credits, keeping the stronger role where both artists hold a song or album, and its favorites, play history and artist recommendations, through ports of Favorites, Activity and Recommendation, dropping rows that would collide; the target keeps its cover, else takes the source's. A removal deletes the artist with its credits, favorites and recommendations, and its play history stays without an artist. Both write through statements and release the managed entities they make stale.

### Album match queue

`album_match_states` holds at most one row per album. A `requested` row carries the request's `source`, `ingest` or `admin`, and its `due_at`; a `needs_review` row carries the review's `reason`, `best_score`, `checked_song_count` and `checked_at`. A `requested` row that a run holds also carries the run's match claim, `claim_id` and `claim_expires_at`, and in `next_due_at` the due time of a request made during the claim; `chk_album_match_states_claim` allows a claim only on a `requested` row and `next_due_at` only beside a claim. The mark lives in its own table rather than an album column because the album capture trigger would publish it to every library reader; its rows publish live changes to admins only. The capture leaves the three claim columns out, so a claim publishes no live update.

#### Requesting a match

`AlbumMatchRequester` implements `AlbumMatchRequestInterface`. `FilesDiscoveredHandler` calls `requestAfterImport()` for the albums a message touched once their songs are committed; it does nothing while `metadata.auto_sync` is off, and its requests fall due after 300 seconds, so that an album's other discs land first. `RequestAlbumMatchHandler` calls `requestForAdmin()` for each `SyncAlbumMessage` the library and genre syncs dispatch; that request falls due at once whatever the setting says. Both skip an album with nothing to match: one marked for review whose song count is still the one the matcher checked, and a matched album whose songs all carry `match_checked_at`.

A request for an album that already waits keeps the earlier due time, and an admin request stays an admin request. `AlbumMergeService` also calls `requestAfterImport()`, for the target album once the merge has committed: `Song::moveToAlbum()` clears `match_checked_at` on each song the merge moves, and the song keeps its recording ID and the fields a match set. That request is best-effort; a failure is logged and the merge stands.

`ApplyMatchHandler` calls it after a manual album apply, as [Apply](#apply) describes. A request for an album a run holds waits beside the claim instead: it leaves `due_at` and the claim alone, keeps in `next_due_at` the earliest due time asked for meanwhile, and queues no run, because the run that holds the album brings one when it ends its claim.

#### Scheduling the queue

`DrainAlbumMatchesMessage` runs on `async`, one album per message, and each run queues the next, so the runs form a chain. `AlbumMatchQueueChain` keeps one chain: the message carries the chain's token, and the application cache holds the current token for as long as the chain's next run needs, plus a margin. A run without a token starts a chain in place of any other, and a run whose chain was replaced ends without matching or queuing a successor.

A request that brings an album's due time forward dispatches a run without a token, delayed until the request falls due, unless another request falls due at or before it. That earlier request's run, or the chain waiting for the earliest due time, reaches this one as well. A request parked for a later retry counts only when it falls due first, so it holds back no other album.

A request a run holds counts from its claim's expiry. Each request writes its row and then looks for others, while a run removes or marks its album and then looks for what is left, so of a request and a finishing run that overlap, at least one sees the other's write. When nothing is left to wait for, the chain releases the queue.

#### Claiming and finishing a request

`DrainAlbumMatchesHandler` claims the request that has been due longest in one statement, `FOR UPDATE SKIP LOCKED`: it writes a claim ID of its own and an expiry 15 minutes ahead on the database clock (`clock_timestamp()`), and never touches `due_at`. While the claim is live no other run takes the request. The run ends its claim by naming the album and the claim ID, each outcome in one statement that also decides on `next_due_at`: when a request waited there, the album waits for a match again from that request's due time, whatever the match found.

Only an outage drops that request, because the album keeps its earlier due time. A claim that no longer holds the album, because another run took the request after the claim lapsed, a request ended the lapsed claim, or the state was removed, changes nothing. A run that died leaves its album held until the claim lapses, and another run then matches the album again.

A song committed while a match runs was not compared, so it keeps a null `match_checked_at` and a later run pairs it. Runs of an old and a new chain can overlap until the old chain's next run ends, and every MusicBrainz call still waits for the shared rate limit. Live claims keep the runs on different albums. Each apply takes the album's row lock and checks the album's release again before it writes.

#### Choosing a release

The run removes every import request when it claims one while `metadata.auto_sync` is off. Otherwise `AutomaticAlbumMatcher` matches the album. An album without a release runs the album search a candidate list runs for the album's own title, the second search included, and `MatchConfidence` decides: the best release, together with its twin editions that score within the lead and agree on every track's title, artist credit and length in track order, however their discs split the tracks, is confident when it has the album's track count, pairs every track within 3 seconds, scores at least 90 and leads the next release by at least 10.

The candidate stands for the twin that keeps the most songs at their own disc and number, the earliest released of those: it is the release recorded, and its tracks give each song its disc and number. A recording ID is written where every twin has the same one at that place in the track order. A matched album looks up only its stored release and pairs against it the songs whose `match_checked_at` is null.

Such a song keeps its pair only when both lengths are known and differ by at most 3 seconds; any other is left unpaired, so the apply marks it as compared and writes nothing to it. When the album is matched, or no longer exists, its request is removed; an album that needs review is marked with the reason, the best score and the song count checked.

Removing the request is one `MERGE`, because a request can commit between the statement's snapshot and its write: under READ COMMITTED `MERGE` evaluates its `WHEN` clauses again on the row as that request left it and keeps the album waiting, where a `DELETE` and an `UPDATE` in one statement leave the row claimed until the claim lapses (`AlbumMatchClaimRaceTest` runs the race in two sessions).

#### Retrying failed work

An apply that met changed rows (`match_stale`) or a deadlock gives back only that album's request, due again in 15 minutes, and a MusicBrainz refusal (`musicbrainz_rejected`) during the search or a lookup gives it back due in 6 hours; the queue goes on with the next album. A real outage, where MusicBrainz or the Cover Art Archive gives no usable answer, gives the request back with its due time and pauses the whole queue for 5, 15 and 60 minutes, then hourly. A cover the Cover Art Archive refuses during the apply is no outage: the album counts as matched. A matcher failure is logged, and its request is given back due an hour later. While requests wait, the run queues the next one a second later, or when the next request falls due.

A run lost to a failed dispatch, or to a message that used up its retries, would leave every request waiting. The scheduled job "Recover the album match queue", seeded by a migration with the expression `*/10 * * * *`, runs `RecoverAlbumMatchQueueCommand`: when a request is due and no chain holds the queue, `RecoverAlbumMatchQueueHandler` logs a warning and dispatches a run without a token, which starts a new chain. A chain that was only late gives way to the new one at its next run.

#### Reading the review list

`ListMatchReviewsHandler` pages through the `needs_review` rows with `MatchReviewReadInterface`, one SQL statement per page, the most recently checked first; its cursor holds the check time and the album's public ID.

### Artist sync

An artist sync fills an artist's empty, unlocked details from MusicBrainz, looked up by the MusicBrainz ID the artist holds: sort name, type, country, disambiguation and the two ends of its life span. It never writes the name or the ID, and it never looks an artist up by name. Metadata's `SyncArtistMessage` carries one artist, the run of the admin sync that queued it or none, and the outages it met in a row; `SyncArtistsMessage` is the artist part of an admin sync. Both are routed on `async` (`metadata.sync_artist` and `metadata.sync_artists`) and handled in Catalog.

Three producers queue `SyncArtistMessage` through `ArtistSyncQueue`:

- `ApplyMatchHandler`, after an album or song apply commits, through `ArtistSyncRequestInterface::requestAfterMatch()`, whatever `metadata.auto_sync` says.
- `FilesDiscoveredHandler`, after each batch commits, through `ArtistSyncRequestInterface::requestAfterImport()`, only while `metadata.auto_sync` is on.
- `QueueArtistSyncsHandler`, which handles `SyncArtistsMessage`: it pages through the artists with a MusicBrainz ID and an empty field among the six (`ArtistPortInterface::identifiedWithMissingDetailsAfter()`), once for the catalog, and checks the job's cancellation before each artist.

`ArtistSyncRequester` implements the interface. `requestAfterImport()` reads the setting when it has an artist to queue, and `requestAfterMatch()` does not read it. The requester runs outside any transaction and reads no catalog row, so it takes no lock; a failure is logged with the artists it did not queue and changes nothing the apply or batch wrote. The resolver's report comes from its own write (`ArtistIdentityResolverInterface::resolveReporting()`), not from a read after the commit, which could not tell an ID the transaction gave from one the artist already held.

#### Pacing artist syncs

`QueuedArtistSyncsInterface`, implemented by `RedisQueuedArtistSyncs` under the key prefix `catalog:artist_sync`, keeps four kinds of expiring entries: a queued mark per artist, so no producer queues an artist twice; an answer mark per artist and MusicBrainz ID for 30 days, so an artist given another ID is asked about the new one; the run records of admin syncs; and one shared due time. One Lua script checks the marks, sets the artist's mark and moves the due time, so each fill falls due `ArtistSyncQueue::SPACING_MS` (2,000 ms) after the latest one reserved, whoever queued it, or at once when none is pending. A queued mark lasts until a day after its fill is due. A lost store asks every artist again at its next producer, which costs requests and writes nothing wrong.

#### Applying artist details

`SyncArtistHandler` asks MusicBrainz only for an artist that holds an ID, misses an unlocked detail, and has no answer mark for that ID, with `MusicBrainzSlotWait::Unbounded`. Then, in one transaction, it locks the artist's row through `ArtistMergePortInterface::lock()`, reads the artist again, and writes only when the artist exists and still holds the ID that was looked up. The fields come from `MatchTicks::automatic()` over `MatchComparisonBuilder::artist()`, limited to the six, and `ArtistMatchWriter`, the writer an artist match uses, writes them; the comparison's merge actions are ignored, so a sync never merges an artist, and nothing is saved when nothing is filled. The row is the handler's only lock, so it cannot wait in a cycle. Every expected outcome ends the message with a log entry and no exception, so a sync adds no failed job to the metadata status:

| MusicBrainz answer | What the handler does |
|--------------------|-----------------------|
| The artist with the stored ID | Fills, then sets the answer mark once the write committed; a write that fails sets none, so the retry asks again |
| 404 | Writes nothing, sets the answer mark |
| `MusicBrainzMerged`, or a record of another ID | Writes nothing and merges nothing, logs both IDs, keeps the stored ID, sets the answer mark |
| `MusicBrainzRejected` | Writes nothing and sets no mark, because a refusal can end; the next producer that queues the artist asks again |
| `MusicBrainzUnavailable` | Queues the same fill again with its run, at the shared pace and no sooner than 5, 15 and then 60 minutes, renewing the queued mark; at the fourth outage in a row it ends, and the next admin sync asks again |

A fill whose run is recorded as cancelled ends without a request. `QueueArtistSyncsHandler` records its run cancelled when its job is cancelled while it queues, and records the run against its job when it finishes, so cancelling the finished job through the job monitor (`QueuedJobWorkInterface`) cancels the run until a day after its last fill was due. Cancelling a run sets the shared due time back to now, since the run's fills still queued make no request; a fill another producer queued meanwhile may then share a two-second step with a new one, which the shared MusicBrainz limit still spaces a second apart.

MusicBrainz answers the lookup of an artist ID it merged into another artist with a permanent redirect to the surviving artist's lookup. `MusicBrainzAdapter::fetchArtist()` does not follow it and answers `MusicBrainzMerged`, naming both IDs. `MusicBrainzAnswer` turns that answer into the 404 `candidate_not_found` of the artist comparison and apply, so an admin re-identifies such an artist by searching in the match dialog.
