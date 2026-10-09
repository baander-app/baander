# app:album:extract-covers

Queue cover art extraction for every album without a cover. Each extraction job reads the artwork embedded in the album's audio files and stores it as the album cover. The command does what `POST /api/albums/covers/extract` does in the admin API, through the same batch job.

## Quick start

```bash
make exec cmd="php bin/console app:album:extract-covers"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:album:extract-covers --json"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the result in JSON: the API's `data` payload plus the batch's job ID |

## Details

The command prints how many albums have no cover and runs the batch job that the API queues. The batch pages through the albums without a cover, 500 at a time, and queues one extraction job per album on the async queue. The API queues the batch itself on that queue; the command runs it in its own process instead and records the run in the job monitor like a queued job.

When the batch finishes, the command prints the number of extraction jobs it queued and the batch's job ID. The covers are extracted by the queue workers, not by the command; follow their progress with [app:monitor:jobs](app-monitor-jobs.md).

The command is safe to run again: it only queues albums that still have no cover. When every album has a cover, the batch queues nothing.

With `--json` the command prints only `{"albums": 3, "jobId": "..."}`. `albums` is the API's `data` payload, the number of albums without cover art when the batch started. `jobId` is the job-monitor ID of the batch run, which the API does not return; pass it to [app:monitor:job:show](app-monitor-job-show.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The batch finished; the message gives the number of jobs queued |
| 1 | The batch failed, for example when the queue was unavailable; the message says why |
