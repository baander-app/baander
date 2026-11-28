# Catalog Imports and Rescans

This page follows a discovered file through metadata probing, identity resolution and the catalog transaction. It also explains how an import handles renamed or retagged files. [Catalog](catalog.md) covers edits and merges; the [inventory](catalog-inventory.md) lists the commands and ports.

## Importing discovered files

`FilesDiscoveredHandler` handles Library's `FilesDiscovered` message and imports the files a scan found. It reads the metadata of up to 50 audio files and probes their audio, then writes their songs, artist credits and genres in one transaction; a delete with files cannot claim the library while that transaction runs (see [Library](library.md#media-file-deletion)). When a delete holds the library, the handler puts the files it has not imported back on the queue.

A song's length comes from the audio probe, never from its tags. A song is credited to the artist its tags name, under a credited name: the first artist value names the artist, and the credited name joins every value with "; ", so the song shows the name it was tagged with even when the artist's own name differs. A file without an artist value is imported without a credit.

Inside the transaction, after the library and file-index locks and before any song is written, one `ArtistIdentityResolverInterface::resolveReporting()` call finds or creates every artist the batch credits, and reports the artists it created with a MusicBrainz ID and those that took on an ID they did not hold. It takes a transaction-scoped advisory lock on each tagged name and on the MusicBrainz ID of each identity that has one, sorted and one key per statement, and resolves each identity by its MusicBrainz artist ID when the file carries exactly one valid ID, otherwise by its exact name; `ArtistIdentityIndex` states the tie-breaks.

A file that names its artists one by one, in its `ARTISTS` values or else its artist values, and gives exactly one valid MusicBrainz artist ID per name credits each name by the ID in the same position: the first as primary under the joined artist values, every other one as featured under its own name. Imports running at once therefore never create two artists for one ID or, without an ID, for one name.

The batch's genre locks sort after the artist locks and are taken after them.

A file whose hash a stored song holds never becomes a new song. At the song's path, or renamed within the song's directory while the old file is gone, it repairs that song, as on a rescan: the song moves to a renamed file's path, takes its length from the probe (a failed probe keeps the stored length) and, when it has no artist credit of any role, takes its tagged credit. A file elsewhere is left alone.

A file with a hash no song holds, at the path of a song in the same library, is that song retagged: the song keeps its ID, album, MusicBrainz ID, lyrics and genres, and takes the new hash, technical fields, length and unlocked tag fields. A title, track, disc or year that the song's `matched_fields` lists changes only when the tags carry a value for it, and then leaves the list; a title or track number taken from the file name does not count.

The new tags' credits replace the song's artist credits, unless `matched_fields` lists `credits` and the tags give the primary artist no MusicBrainz ID: the match's credits then stay, and the batch neither finds nor creates the tagged artist. When a batch holds retagged songs, its transaction first takes every artist and genre key the batch may write in one sorted pass, then locks the retagged songs' rows `FOR NO KEY UPDATE` in ID order, the order a match apply takes, and reads those songs again before it decides.

The song stays in its album, and an album is created only for new songs.

After the message's last batch, each album it touched that has no artist credit gets one, in a transaction that locks the album row `FOR NO KEY UPDATE`: from the first album-artist tag among the album's files in the message, in path order, or else among the files of its stored songs; else to the artist every song of the album is credited to; else none. An album that has a credit of any role keeps it.

Ingest then asks `AlbumMatchRequestInterface::requestAfterImport()` for automatic MusicBrainz matches of the albums the message touched (see [Album match queue](catalog-matching.md#album-match-queue)); when that call throws, ingest logs the error and carries on. Right after each batch commits, beside its lyrics request, ingest passes the artists that batch's resolver call reported to `ArtistSyncRequestInterface::requestAfterImport()`, which queues their [artist sync](catalog-matching.md#artist-sync) while `metadata.auto_sync` is on, so a later batch's failure keeps them. An album artist that only the album credit after the last batch creates is not reported; the next admin sync fills it.

## Video Import and Rescan

For a video library, [`FilesDiscoveredHandler`](../../../src/Catalog/Application/CommandHandler/FilesDiscoveredHandler.php) sends each batch through [`VideoFileIngest`](../../../src/Catalog/Application/Service/VideoFileIngest.php). The service probes before its write transaction. Inside the transaction it checks the library's delete claim and the scan's indexed path and hash before it writes a video and its placement. An older observation cannot replace a newer one.

A video record is identified by its hash. A placement records its library, relative path, parse and owning title. [`MovieFileIngest`](../../../src/Catalog/Application/Service/MovieFileIngest.php) groups feature versions and extras by title folder. A movie file at the library root uses its relative path as the grouping key. [`TvLibraryIngest`](../../../src/Catalog/Application/Service/TvLibraryIngest.php) attaches a placement to its episodes or to the series as an extra. One placement can play several episodes, and an episode can have several versions. When a renamed file or folder is found through a known placement, the new path replaces the old one without changing the title's identity.

When a video batch commits, the handler asks Catalog to match the affected titles. It also asks for titles affected by sidecar observations, without creating video records for the sidecars. A queue failure is logged after import so it cannot roll back a committed placement. See [Video Matching](catalog-matching.md#video-matching).

[`VideoAvailability`](../../../src/Catalog/Infrastructure/VideoAvailability.php) compares the hash at the placement's resolved library path with the video's stored hash. Member queries apply that check within the owning library before counting or paging. When a file returns, its placement and viewing state are available again. Playback also resolves the file inside the library root and can try another available placement when a path has vanished.

