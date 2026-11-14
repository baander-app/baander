# app:metadata:sync

Queue a metadata sync from the external providers for every library, or with `--source=genres` only the genre sync. The command does what the **Trigger Sync** and **Sync Genres** buttons on the admin metadata page do, `POST /api/admin/metadata/trigger-sync` in the admin API, through the same sync job.

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

Without a source, the sync queues one library sync per library. Each library sync, when a worker runs it, queues a sync of every album in the library and of its songs.

An album sync requests the album's MusicBrainz match, whatever the `metadata.auto_sync` setting says, unless the album has nothing to match; the album match queue then runs it as [Automatic matching](../media/external-apis.md#automatic-matching) describes. A confident match sets IDs and artist credits and fills empty fields, and never replaces a field that holds a value; any other album is left for review. A song sync adds the genres of the MusicBrainz recording found for the song's title and writes nothing else.

The genre sync queues a sync of every album and song in the catalog, marked as forced updates. The mark changes nothing: an album sync requests the same match, which replaces no field value, and a forced song sync adds genres as an ordinary one does. The genre sync queues nothing for the libraries.

The command runs the sync job in its own process and records the run in the job monitor like a queued job. When the job finishes, the command prints the number of jobs it queued, one per library or one per album and song, and the job ID. The queue workers perform the queued syncs; follow them with [app:monitor:jobs](app-monitor-jobs.md) or [app:metadata:status](app-metadata-status.md).

With `--json` the command prints only `{"jobsDispatched": 3, "jobId": "..."}`. `jobsDispatched` is the API's `data` payload, the number of jobs queued. `jobId` is the job-monitor ID of the sync run, which the API does not return; pass it to [app:monitor:job:show](app-monitor-job-show.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The sync job finished; the message gives the number of jobs queued |
| 1 | The sync job failed, for example when the queue was unavailable; the message says why |
| 2 | `--source` is not `genres` |
