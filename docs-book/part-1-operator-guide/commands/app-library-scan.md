# app:library:scan

Scan a media library, or every library, for new, changed, returning, or missing files. The command is the counterpart of the admin panel's scan buttons (`POST /api/libraries/{id}/scan` and `POST /api/libraries/scan-all` in the API) and follows the same rule: a library that is already scanning, or that a delete with files holds, is not scanned.

## Quick start

Run from the installed-instance host shell with the `dc` function from
[Getting started](../getting-started.md#command-context). Paths name locations
inside the application container.

```bash
dc exec app php bin/console app:library:scan my-music
```

Every library, one after another:

```bash
dc exec app php bin/console app:library:scan --all
```

Release the claim of a scan, or of a delete with files, that is gone:

```bash
dc exec app php bin/console app:library:scan my-music --release
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `library` | Unless `--all` is given | The library's public ID or slug |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--all` | — | Scan every library that is not already scanning, one after another |
| `--rescan` | — | Read every file again, including files the index already knows. Not combined with `--release` |
| `--release` | — | Only release the library's claim; a scan claim marks its scan failed, a delete claim leaves the scan status alone. Refused while the claim is live. Takes one library and no other option than `--force` |
| `--force` | — | With `--release`, release a live claim too. Only valid with `--release` |
| `--json` | — | After the run, print what the scan endpoint returns, in JSON: the library as it is after its scan, or with `--all` the `dispatched` and `skipped` counts of `scan-all`, where `dispatched` counts the scans the command started. Progress goes to stderr. Not combined with `--release` |

## Details

Discovery supports `music` and `movie`; scans of other registered library types
fail as unsupported. A successful scan confirms discovery and committed ingestion
intents, while catalog import continues in the worker pair. See
[Library management](../library-management.md) for the stages and their observable
results.

Starting a scan claims the library: its scan status becomes `scanning`, and while the claim holds, neither the admin panel nor this command starts another scan of it, and a delete with files ([app:album:delete](app-album-delete.md), [app:song:delete](app-song-delete.md)) is refused. A delete with files claims the library the same way while it runs, without changing the scan status; a scan started then is refused with the message that a delete with files is in progress (reason `library_busy`, holder `delete`). The command claims the library, then runs the scan in its own process and prints each stage: the claim, then the number of files discovered, processed and skipped, the number of directories queued for ingestion, and the job ID. The run appears in the job monitor under that ID, like a scan the admin panel queues. Ingesting the queued directories happens afterwards in the worker containers.

The admin panel queues its scans on the web server's task workers, which a console process cannot reach, so the command offers no option to queue a scan.

With `--all`, the command claims every library that is not scanning, prints the ones it skipped, scans the claimed ones in display order, and ends with the list of libraries it started.

A scan that fails ends its claim and marks the scan `failed`, so the next scan can start. Ctrl+C (SIGINT) or SIGTERM interrupts the running scan the same way, records it as failed in the job monitor, and releases the claims of libraries `--all` has not reached. A signal between two scans of `--all` stops the run before the next one and releases the claims of the libraries not started.

A scan works one directory at a time: it reads every file of the directory to tell new and changed files from known ones, then queues the directory for ingestion. Cancelling the scan's job in the job monitor, or with [app:monitor:job:cancel](app-monitor-job-cancel.md), stops the scan before its next directory. The job ID appears in the job monitor while the scan runs. The command prints `The scan of "<library>" was cancelled: Job "<jobId>" has been cancelled.`, the job's status becomes `cancelled`, and the library's scan becomes `failed`, which ends its claim. Directories queued before the scan stopped are still ingested, and the next scan picks up the rest.

With `--all`, a cancelled scan stops only its own library: the command goes on with the next one and exits with code 1 at the end.

### Queued directories and delivery failures

The directory count means that each directory's index updates and ingestion intent
committed together. The worker's outbox relay then sends the directory for
ingestion; it can do this while the scan continues. A failed index write or intent
capture rolls back that directory and fails the scan. Earlier committed
directories remain pending or delivered.

Before importing a queued file, the worker compares its recorded hash with the
latest available file-index observation for that library and path. It skips a
snapshot that a later scan replaced or marked unavailable, or whose index row was
explicitly removed. It repeats the check in the transaction that writes catalog
records. A mixed message still imports its current files. An unchanged file that
returns and is rescanned is eligible again, as is a current duplicate delivery.
This compares recorded observations; it does not prevent files changing on disk
between scans.

A Redis send failure leaves the committed intent for the relay to retry. It does
not undo the directory's index or fail a scan that already accepted it. After five
failed relay attempts, the intent is dead-lettered. See
[app:outbox:consume](app-outbox-consume.md) for relay operation and diagnostics.
Once the underlying failure is fixed, run `app:library:scan my-music --rescan`
to queue the indexed files again. An ordinary incremental scan skips unchanged
available files, including those whose earlier intent exhausted its retries.

### Missing and returning files

A completed scan retains catalog entries and file-index records when sources are
missing, including when every file under a library root or mounted subtree has
disappeared. It marks those sources unavailable. Music remains visible with an
“Unavailable” label, and the web player skips it during bulk playback and disables
individual playback actions. Explicit catalog deletion remains separate.

Availability records the last discovery or ingestion observation. It cannot tell
whether a mount failed or a file was permanently removed, and it is not a live
filesystem check. Tracks without a file-index observation remain unavailable until
a scan confirms their source. A failed or cancelled scan does not mark unvisited
paths unavailable; directories already queued may still be ingested.

Restore the storage and run another scan. Unchanged returning files are queued
again, including files that disappeared before their first queued import. Both
ordinary scans and `--rescan` reconcile missing paths. Ingestion also repairs a
renamed music file when its content hash matches an existing song in the same
album and directory, its old path is missing, and its new path remains inside the
available library root. The song keeps its identity, metadata and playlist links.
Cross-directory and cross-album moves are outside this repair.

### The claim lease

A claim is a lease of 15 minutes. While reading files and directories, a running scan renews it at most once a minute. Each directory write and each batch of missing-file observations also checks and renews the claim inside its database transaction, without waiting for that interval.

When no scan renews a claim, it lapses: the library then reads as `failed`, and the next scan from the admin panel or this command takes the library over. This frees a library whose scan was lost, such as a scan the admin panel queued before the web server restarted, or a scan whose process was killed. The library waits at most 15 minutes after the last renewal.

The lease of a queued scan starts when the scan is queued. If the scan waits longer than 15 minutes for a task worker, its claim lapses while it waits. When the scan then starts, it takes its claim back, unless another scan claimed the library in the meantime; in that case it stops with the message that a scan is already in progress, and only the newer scan runs. A scan that pauses longer than the lease, for example while it reads one very large file from slow storage, can lose its claim the same way. It stops when its next heartbeat or write check detects the lost claim. A replaced scan cannot commit another directory or mark more sources unavailable.

`--release` ends a claim and names its kind. It waits for any scan write transaction already holding the claim row to commit or roll back. Once release completes, the old scan cannot commit another write batch; previously accepted directories remain eligible for ingestion.

A scan claim marks its scan `failed`; a delete claim leaves the scan status as it was. Without `--force`, it releases only a claim that has lapsed; a live claim is refused, because its scan or delete may still run, and releasing it would let a scan start next to it. Use `--force` once you know the process is gone, for example right after a web server restart dropped queued scans, or after a delete's process was killed. A scan whose claim was released this way stops at its next heartbeat or write check; a delete stops unlinking at its next renewal and lists the files it did not reach as left. Releasing a library that holds no claim changes nothing and succeeds.

A delete with files holds its claim under the same 15-minute lease and renews it while it unlinks files. A scan that finds a lapsed delete claim takes the library over, and a delete that finds the lapsed claim of a scan that died takes it over and marks that scan `failed`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Every scan started completed; with `--release`, the claim is released or there was none |
| 1 | The library is already scanning or a delete with files holds it, no library has the public ID or slug, a scan failed, was cancelled or lost its claim, or `--release` found a live claim without `--force`; the message says why |
| 2 | Invalid options: no library and no `--all`, both, `--release` with an option other than `--force`, or `--force` without `--release` |
| 130 | Interrupted by SIGINT; the unfinished scans are released |
| 143 | Interrupted by SIGTERM; the unfinished scans are released |
