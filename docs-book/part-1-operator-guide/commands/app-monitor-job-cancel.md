# app:monitor:job:cancel

Cancel a running background job, or the work a finished job queued. A running job stops at its next checkpoint. The command does what the **Cancel** action of the admin **Job Monitor** does through `POST /api/monitor/jobs/{jobId}/cancel`, with the same checks.

## Quick start

```bash
make exec cmd="php bin/console app:monitor:job:cancel <jobId>"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:monitor:job:cancel <jobId> --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `jobId` | Yes | The job's ID, as [app:monitor:jobs](app-monitor-jobs.md) lists it |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the result, as the admin API's `data` payload, in JSON |

## Details

The command sets the job's cancellation flag in Redis for one hour and returns. The job stops at its next checkpoint, which is between two items of its work, and its status in the monitor becomes `cancelled`. A cancelled job is not retried and does not go to the failure transport. Work it queued before it stopped stays queued, except for a lyrics bulk fetch: its queued fetches are skipped without calling LRCLIB.

A lyrics bulk fetch finishes queueing in seconds, but its fetches can run for hours. Cancelling the job after it finished cancels the fetches still waiting: they are skipped when they come due, and the job's status changes from `finished` to `cancelled`. This works until a day after the run's last fetch was due. [app:monitor:job:show](app-monitor-job-show.md) and the admin job detail show whether a job can still be cancelled.

These jobs have checkpoints:

| Job (monitor name) | Started by | Stops before |
|--------------------|------------|--------------|
| `SyncLibraryMessage` | A metadata sync, one job per library | Its next album |
| `SyncMetadataCommand` | [app:metadata:sync](app-metadata-sync.md) with `--source genres` | Its next album |
| `BulkFetchLyricsCommand` | The admin **Fetch Missing Lyrics** button or [app:lyrics:fetch](app-lyrics-fetch.md) | Its next song. The fetches it already queued are skipped when they come due, also when the job is cancelled after it finished |
| `BatchExtractCoversCommand` | The admin cover extraction or [app:album:extract-covers](app-album-extract-covers.md) | Its next page of 500 albums |
| `ScanLibraryCommand` | A library scan from the admin panel, one job per library with **Scan all**, or [app:library:scan](app-library-scan.md) | Its next directory (an album or a movie folder) |

A cancelled library scan marks the library's scan `failed`, which ends its claim, so the next scan can start. The directories it queued for ingestion before it stopped are still ingested; the next scan picks up the rest.

Other jobs have no checkpoints and run to the end; the flag does not change them. Recommendation jobs have their own cancel command, [app:recommendation:job:cancel](app-recommendation-job-cancel.md).

A console command that runs a job inline, such as `app:lyrics:fetch`, prints `Job "<jobId>" has been cancelled.` and exits with code 1 when the job is cancelled.

Cancelling a job that has failed is refused, and so is cancelling a finished job with no queued work waiting. Cancelling a job again sets the flag again and succeeds. If the same job runs again within the hour, for example after a worker restart, it stops at its first checkpoint.

With `--json` the command prints only `{"cancelled": true}`, the API's `data` object.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Cancellation was requested, or the finished job's queued work was cancelled; the message says which |
| 1 | No job has this ID, the job has failed or has finished with no queued work waiting, or Redis could not be reached; the message says which |
