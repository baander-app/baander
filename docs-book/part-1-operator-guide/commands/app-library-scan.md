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

Release the claim a killed scan left behind:

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
| `--release` | — | Only release the library's scan claim and mark its scan failed. Takes one library and no other option |

## Details

Starting a scan claims the library: its scan status becomes `scanning`, and while it stays so, neither the admin panel nor this command starts another scan of it. The command claims the library, then runs the scan in its own process and prints each stage: the claim, then the number of files discovered, processed and skipped, the number of directories queued for ingestion, and the job ID. The run appears in the job monitor under that ID, like a scan the admin panel queues. Ingesting the queued directories happens afterwards in the worker containers.

The admin panel queues its scans on the web server's task workers, which a console process cannot reach, so the command offers no option to queue a scan.

With `--all`, the command claims every library that is not scanning, prints the ones it skipped, scans the claimed ones in display order, and ends with the list of libraries it started.

A scan that fails ends its claim and marks the scan `failed`, so the next scan can start. Ctrl+C (SIGINT) or SIGTERM interrupts the running scan the same way, records it as failed in the job monitor, and releases the claims of libraries `--all` has not reached.

A process killed with SIGKILL, or a web server that stops in the middle of a scan, leaves the claim in place, and every new scan of the library is refused. Check that no scan of the library is still running, then clear the claim with `--release`. Releasing a library that holds no claim changes nothing and succeeds.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Every scan started completed; with `--release`, the claim is released or there was none |
| 1 | The library is already scanning, no library has the UUID or slug, or a scan failed; the message says why |
| 2 | Invalid options: no library and no `--all`, both, or `--release` with another option |
| 130 | Interrupted by SIGINT; the unfinished scans are released |
| 143 | Interrupted by SIGTERM; the unfinished scans are released |
