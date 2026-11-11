# app:transcode:job:cleanup

End the transcode sessions whose client has gone, then remove the transcode jobs that no session uses any more, together with their transcoded output. The command does what `POST /api/transcode/jobs/cleanup` does in the admin API.

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

A transcode job holds the segments of one video at one quality tier. A job stays after its last session ends. The command finds each job without a session, deletes its output directory and then the job itself, and prints what it did, such as `Expired 2 stale transcode sessions and removed 3 orphaned transcode jobs.`

A client that closes without cancelling leaves its session behind, and the session keeps its job: the worker holds the encode at the lead throttle and keeps its transcode slot. The command first expires each stale session. A session is stale when no playback report and no stream signing has touched it for 24 hours, the longest lifetime of a signed stream URL, and its presentation is not finished. A paused player renews its stream URL, so it does not expire. Expiry cancels the encode, ends the session and releases the job.

A job whose worker has not yet stopped the cancelled encode stays for the next run, so a job released by expiry is usually removed one run later. The worker stops within seconds.

The command runs the same use case as the API's cleanup endpoint; nothing schedules it, so run it from the console, the admin panel or your own scheduler. A job whose output directory is still retained stays for a later run. A job whose output or record cannot be deleted is logged with its id and also stays; the run removes every other job and then exits 1. After a job is removed, a later session for the same video and tier transcodes it again.

The command asks for no confirmation: it ends only sessions no client can still be playing, and removes only output that no session uses and that a new session can rebuild.

With `--json` the command prints only `{"expired": 2, "cleaned": 3}`, the API's `data` object with the number of sessions expired and jobs removed.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Cleanup finished, possibly with nothing to remove |
| 1 | The jobs could not be read, or at least one could not be removed; the message gives the removed and failed counts and the log names each job |
