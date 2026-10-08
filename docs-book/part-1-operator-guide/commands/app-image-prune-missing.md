# app:image:prune-missing

Delete the image records whose files no longer exist in storage, or with `--dry-run` list them without deleting anything. The command does what the Storage tab of the admin media page does: `--dry-run` shows what `GET /api/admin/media/missing-check` returns, and without it the command runs the prune that `POST /api/admin/media/prune-missing` queues.

## Quick start

List the images whose files are missing:

```bash
make exec cmd="php bin/console app:image:prune-missing --dry-run"
```

Delete their records:

```bash
make exec cmd="php bin/console app:image:prune-missing"
```

Without a terminal, for example in a script:

```bash
make exec cmd="php bin/console app:image:prune-missing --force"
```

## Options

| Option | Description |
|--------|-------------|
| `--dry-run` | List the images whose files are missing and delete nothing |
| `--json` | With `--dry-run`, print the check exactly as the API's `data` object, in JSON |
| `--force` | Delete without asking; required when no terminal is attached |

## Details

With `--dry-run` the command checks the file of every image record. It prints a table with the image ID, the type of the image's owner (such as `album` or `artist`) and the storage path of each missing file, and the number of missing files out of all images. When every file is present it says so.

Without `--dry-run` the command asks before it deletes anything. Without a terminal and without `--force` it deletes nothing and exits with code 2. It then checks every image file again and deletes the record of each missing one. The web page queues this prune on the server's task workers; the command runs it in its own process, because a console process cannot reach them. The run is recorded in the job monitor like a queued job, and the command prints its job ID and the number of records deleted.

Deleted records cannot be restored. The files were already gone, so nothing in storage changes.

`--json` belongs to the check. Given without `--dry-run`, the command refuses to run and exits with code 2, so a script that meant to read the check never deletes anything.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Check printed, or prune finished |
| 1 | The check or the prune failed, or the operator declined; the message says why |
| 2 | No terminal is attached and `--force` was not given, or `--json` was given without `--dry-run`; nothing was deleted |
