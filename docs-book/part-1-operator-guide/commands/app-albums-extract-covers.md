# app:albums:extract-covers

Dispatch cover art extraction jobs for all albums that are missing cover art. Embedded artwork is extracted from audio files and stored as album covers.

## Quick start

```bash
make exec cmd="php bin/console app:albums:extract-covers"
```

## Details

The command queries the database for albums without cover art, then dispatches an asynchronous job for each one. Each job extracts embedded image data from the album's audio files.

Processing happens asynchronously via the message bus — the command returns after dispatching all jobs. Check job status using the job monitor.

If all albums already have cover art, the command exits immediately with an informational message.

Albums are processed in batches of 500.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | All jobs dispatched successfully (or nothing to do) |
| 1 | Something went wrong — check the error message |

## Tips

- Run this after an initial library scan to populate cover art across your collection.
- The command is safe to run multiple times — it only targets albums without existing covers.
- Cover extraction is asynchronous. Use the job monitor to track progress.
