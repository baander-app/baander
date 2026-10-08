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

## Options

| Option | Description |
|--------|-------------|
| `--source` | `genres` for the genre sync only; leave it out to sync every library |

## Details

Without a source, the sync queues one library sync per library. Each library sync, when a worker runs it, queues a sync of every album in the library and of its songs. Album and song data that is already filled in is kept.

The genre sync queues a sync of every album and song in the catalog with forced updates, so the providers' data, genres included, replaces what is stored. It queues nothing for the libraries.

The command runs the sync job in its own process and records the run in the job monitor like a queued job. When the job finishes, the command prints the number of jobs it queued, one per library or one per album and song, and the job ID. The queue workers perform the queued syncs; follow them with [app:monitor:jobs](app-monitor-jobs.md) or [app:metadata:status](app-metadata-status.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The sync job finished; the message gives the number of jobs queued |
| 1 | The sync job failed, for example when the queue was unavailable; the message says why |
| 2 | `--source` is not `genres` |
