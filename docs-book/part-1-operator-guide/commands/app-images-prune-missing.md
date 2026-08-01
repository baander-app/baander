# app:images:prune-missing

Remove image records whose files no longer exist on disk. The command first checks for missing files, lists them, then dispatches an async prune job to clean up the database.

## Quick start

```bash
make exec cmd="php bin/console app:images:prune-missing"
```

## Details

The command runs a check against the filesystem for every image record. If all files are present, it reports the total and exits. When missing files are found, it lists each offending record (ID, type, path) and then dispatches a background prune job — actual deletion happens asynchronously, not inline.

This command takes no arguments or options.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Check completed (and prune job dispatched if any were missing) |

## Tips

- Safe to run repeatedly — it only acts when missing files are detected.
- The prune itself runs async. Check the job monitor for completion rather than expecting inline deletion.
- Use this after moving or deleting media files outside Baander.
