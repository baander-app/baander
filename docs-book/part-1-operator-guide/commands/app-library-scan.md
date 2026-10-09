# app:library:scan

Scan a media library, or every library, for new, changed, or removed files. The command is the counterpart of the admin panel's scan buttons (`POST /api/libraries/{id}/scan` and `POST /api/libraries/scan-all` in the API) and follows the same rule: a library that is already scanning is not scanned again.

## Quick start

```bash
make exec cmd="php bin/console app:library:scan my-music"
```

Every library, one after another:

```bash
make exec cmd="php bin/console app:library:scan --all"
```

Release the claim of a scan that is gone:

```bash
make exec cmd="php bin/console app:library:scan my-music --release"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `library` | Unless `--all` is given | The library's UUID or slug |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--all` | — | Scan every library that is not already scanning, one after another |
| `--rescan` | — | Read every file again, including files the index already knows. Not combined with `--release` |
| `--release` | — | Only release the library's scan claim and mark its scan failed. Refused while the claim is live. Takes one library and no other option than `--force` |
| `--force` | — | With `--release`, release a live claim too. Only valid with `--release` |
| `--json` | — | After the run, print what the scan endpoint returns, in JSON: the library as it is after its scan, or with `--all` the `dispatched` and `skipped` counts of `scan-all`, where `dispatched` counts the scans the command started. Progress goes to stderr. Not combined with `--release` |

## Details

Starting a scan claims the library: its scan status becomes `scanning`, and while the claim holds, neither the admin panel nor this command starts another scan of it. The command claims the library, then runs the scan in its own process and prints each stage: the claim, then the number of files discovered, processed and skipped, the number of directories queued for ingestion, and the job ID. The run appears in the job monitor under that ID, like a scan the admin panel queues. Ingesting the queued directories happens afterwards in the worker containers.

The admin panel queues its scans on the web server's task workers, which a console process cannot reach, so the command offers no option to queue a scan.

With `--all`, the command claims every library that is not scanning, prints the ones it skipped, scans the claimed ones in display order, and ends with the list of libraries it started.

A scan that fails ends its claim and marks the scan `failed`, so the next scan can start. Ctrl+C (SIGINT) or SIGTERM interrupts the running scan the same way, records it as failed in the job monitor, and releases the claims of libraries `--all` has not reached. A signal between two scans of `--all` stops the run before the next one and releases the claims of the libraries not started.

A scan works one directory at a time: it reads every file of the directory to tell new and changed files from known ones, then queues the directory for ingestion. Cancelling the scan's job in the job monitor, or with [app:monitor:job:cancel](app-monitor-job-cancel.md), stops the scan before its next directory. The job ID appears in the job monitor while the scan runs. The command prints `The scan of "<library>" was cancelled: Job "<jobId>" has been cancelled.`, the job's status becomes `cancelled`, and the library's scan becomes `failed`, which ends its claim. Directories queued before the scan stopped are still ingested, and the next scan picks up the rest.

With `--all`, a cancelled scan stops only its own library: the command goes on with the next one and exits with code 1 at the end.

### The claim lease

A claim is a lease of 15 minutes. A running scan renews it at most once a minute as it reads files and directories. When no scan renews a claim, it lapses: the library then reads as `failed`, and the next scan from the admin panel or this command takes the library over. This frees a library whose scan was lost, such as a scan the admin panel queued before the web server restarted, or a scan whose process was killed. The library waits at most 15 minutes after the last renewal.

The lease of a queued scan starts when the scan is queued. If the scan waits longer than 15 minutes for a task worker, its claim lapses while it waits. When the scan then starts, it takes its claim back, unless another scan claimed the library in the meantime; in that case it stops with the message that a scan is already in progress, and only the newer scan runs. A scan that pauses longer than the lease, for example while it reads one very large file from slow storage, can lose its claim the same way. It stops at its next renewal and publishes nothing more.

`--release` ends a claim at once and marks its scan `failed`. Without `--force`, it releases only a claim that has lapsed; a live claim is refused, because its scan may still run, and releasing it would let a second scan of the same library start next to it. Use `--force` once you know the scan is gone, for example right after a web server restart dropped queued scans. A scan whose claim was released this way stops at its next renewal. Releasing a library that holds no claim changes nothing and succeeds.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Every scan started completed; with `--release`, the claim is released or there was none |
| 1 | The library is already scanning, no library has the UUID or slug, a scan failed, was cancelled or lost its claim, or `--release` found a live claim without `--force`; the message says why |
| 2 | Invalid options: no library and no `--all`, both, `--release` with an option other than `--force`, or `--force` without `--release` |
| 130 | Interrupted by SIGINT; the unfinished scans are released |
| 143 | Interrupted by SIGTERM; the unfinished scans are released |
