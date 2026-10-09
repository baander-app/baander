# app:transcode:job:cleanup

Remove the transcode jobs that no session uses any more, together with their transcoded output. The command does what `POST /api/transcode/jobs/cleanup` does in the admin API.

## Quick start

```bash
make exec cmd="php bin/console app:transcode:job:cleanup"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:transcode:job:cleanup --json"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the result, as the admin API's `data` payload, in JSON |

## Details

A transcode job holds the segments of one video at one quality tier. Sessions share jobs, so a job stays after its last session ends. The command finds each job without a session, deletes its output directory and then the job itself, and prints how many jobs it removed, such as `Removed 3 orphaned transcode jobs.`

The command runs the same use case as the API and as the scheduled `CleanupOrphanedJobsCommand`. Deleting an output directory is best effort: a directory that cannot be deleted does not stop the job from being removed. A later session for the same video and tier transcodes it again.

The command asks for no confirmation: it removes only output that no session uses and that a new session can rebuild.

With `--json` the command prints only `{"cleaned": 3}`, the API's `data` object with the number of jobs removed.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Cleanup finished, possibly with nothing to remove |
| 1 | The jobs could not be read or removed; the message says why |
