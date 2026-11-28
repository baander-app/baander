# app:metadata:sync

Queue a metadata sync from the external providers for every library and for the artists, or with `--source=genres` only the genre sync. The command does what the **Trigger Sync** and **Sync Genres** buttons on the admin metadata page do, `POST /api/admin/metadata/trigger-sync` in the admin API, through the same sync job.

## Quick start

```bash
make exec cmd="php bin/console app:metadata:sync"
```

Run only the genre sync:

```bash
make exec cmd="php bin/console app:metadata:sync --source=genres"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:metadata:sync --json"
```

## Options

| Option | Description |
|--------|-------------|
| `--source` | `genres` for the genre sync only; leave it out to sync every library |
| `--json` | Print only the result in JSON: the API's `data` payload plus the job ID |

## Details

Without a source, the command queues one job per library, one Artist sync and one
Person sync. A music library job queues its albums and songs. A movie or TV library
job queues a refresh for the library's matched titles.

An album sync requests the album's MusicBrainz match, whatever the `metadata.auto_sync` setting says, unless the album has nothing to match; the album match queue then runs it as [Automatic matching](../media/musicbrainz-matching.md#automatic-matching) describes. A confident match sets IDs and artist credits and fills empty fields, and never replaces a field that holds a value; any other album is left for review. A song sync adds the genres of the MusicBrainz recording found for the song's title and writes nothing else.

A video refresh uses the provider and entry ID already stored on each movie or
series. For a series, it also uses the stored episode ordering. Locked fields
keep their current values. Cast and crew are handled separately. When the provider
omits either list, the stored credits for that list remain. [Metadata context](../../part-2-developer-guide/contexts/metadata.md#commands-and-handlers).

Screen credits use the person IDs supplied by the matched provider to resolve
People. Changed credits queue profile enrichment after the metadata is saved.
Workers need `TMDB_BEARER_TOKEN` for TMDB requests and
`TVDB_API_KEY` for TheTVDB requests.
[Worker configuration](../media/external-apis.md#configuration),
[People and credits](../media/library-management.md#people-and-credits).

The artist sync, when a worker runs it, queues the fill of every artist that holds a MusicBrainz ID and has its sort name, type, country, disambiguation, or either end of its life span empty, or needs linking to a Person. Each Artist is queued once across its libraries. It skips an artist whose fill is queued already, and one whose ID MusicBrainz answered about within the last 30 days. The fills fall due two seconds apart. Each fill looks the artist up by its stored ID and writes only empty fields that are not locked, never the artist's name or ID; [Artist details](../media/musicbrainz-matching.md#artist-details) describes what a fill writes and when it writes nothing. An album or song match, and an import while `metadata.auto_sync` is on, queue the fill of an artist they identify without waiting for this sync. To see MusicBrainz's current values for one artist within the 30 days, use the artist's match dialog or [app:metadata:match:compare](app-metadata-match-compare.md). Cancelling the artist sync's job, `SyncArtistsMessage`, with [app:monitor:job:cancel](app-monitor-job-cancel.md) ends the fills it queued without a request to MusicBrainz, also after the job finished.

The genre sync queues a sync of every album and song in the catalog, marked as forced updates. The mark changes nothing: an album sync requests the same match, which replaces no field value, and a forced song sync adds genres as an ordinary one does. The genre sync queues nothing for the libraries and no artist sync.

The command runs the sync job in its own process and records the run in the job monitor like a queued job. When the job finishes, the command prints the number of jobs it queued, one per library plus the Artist and Person syncs, or one per album and song, and the job ID. The queue workers perform the queued syncs; follow them with [app:monitor:jobs](app-monitor-jobs.md) or [app:metadata:status](app-metadata-status.md).

With `--json` the command prints only `{"jobsDispatched": 3, "jobId": "..."}`. `jobsDispatched` is the API's `data` payload, the number of jobs queued. `jobId` is the job-monitor ID of the sync run, which the API does not return; pass it to [app:monitor:job:show](app-monitor-job-show.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The sync job finished; the message gives the number of jobs queued |
| 1 | The sync job failed, for example when the queue was unavailable; the message says why |
| 2 | `--source` is not `genres` |
