# app:song:delete

Delete a song, and optionally its audio file. The command does what `DELETE /api/admin/songs/{publicId}` does in the admin API, through the same use case. `DELETE /api/songs/{publicId}` runs the same delete without the file. With `--dry-run` it shows what `GET /api/admin/songs/{publicId}/delete-preview` shows.

## Quick start

Preview the delete, including a check of the audio file:

```bash
make exec cmd="php bin/console app:song:delete V1StGXR8_Z5jdHi6B-myT --dry-run --delete-files"
```

Delete the song from the catalog and leave the audio file on disk:

```bash
make exec cmd="php bin/console app:song:delete V1StGXR8_Z5jdHi6B-myT"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The song's public ID, as the API and the web app show it |

## Options

| Option | Description |
|--------|-------------|
| `--delete-files` | Also delete the audio file from disk, inside the library root only; requires `--force` |
| `--dry-run` | Print what the delete would remove and change nothing; with `--delete-files`, check the file |
| `--force` | Delete without asking; required when no terminal is attached, and always required with `--delete-files` |
| `--json` | Print the API response's `data` payload: the delete result, or with `--dry-run` the preview |

## Details

On a terminal the command asks before it deletes anything. Without a terminal and without `--force` it deletes nothing and exits with code 2. `--delete-files` needs `--force` even on a terminal. `--json` does not stand in for `--force`.

With `--delete-files`, the file is checked before anything changes. The command refuses the delete and deletes nothing when the file, or the file a symlink points to, lies outside the library root (exit code 2), when the server cannot write its directory (exit code 1), when a scan or another delete with files holds the library (exit code 1), when the library folder is not available, such as unmounted storage (exit code 1), or when the file is missing (exit code 1, reason `all_files_missing`), since that is how a file reads when the storage under it is not mounted. When the file is really gone, delete the song without `--delete-files`.

The song and its file index entry are then deleted together, and the file is unlinked after that. A symlink is removed as a link; its target stays. When the file cannot be removed, the song is still deleted, the result lists the file as left with the reason, and the command exits with code 1. The next library scan imports a file left on disk again.

A delete with `--delete-files` holds the song's library until it ends, as [app:album:delete](app-album-delete.md) describes: a scan started meanwhile is refused with reason `library_busy`, the library's scan status and last scan time stay as they were, and the delete releases the library however it ends, also when SIGINT or SIGTERM interrupts it. Imports a scan queued wait until the delete ends and skip files that are gone, so the song does not come back.

The last check of the file runs immediately before it is unlinked. PHP has no `unlinkat()`, so a parent directory replaced with a symlink in the moment between that check and the unlink is not caught, and the unlink follows the symlink. Only someone who can write the library's directories can do this; it is an accepted limit.

Without `--delete-files` the audio file stays on disk and keeps its index entry, so an incremental scan does not import it again until it changes.

`--dry-run` shows the song, its album, its file and size, and the playlists that lose it. With `--delete-files` it also shows the file's verdict (`deletable`, `missing`, `outside_root` or `directory_not_writable`) and whether a scan or another delete holds the library. It needs no `--force`.

To delete a whole album, use [app:album:delete](app-album-delete.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Song deleted, or the preview printed |
| 1 | No song has the public ID, a scan or another delete holds the library, the library folder is not available, the file is missing, the server cannot write the song directory, the file was left on disk, the operator declined, or another error occurred; the message says why |
| 2 | The public ID is malformed, the file lies outside the library root, `--delete-files` was given without `--force`, or no terminal is attached and `--force` was not given; nothing was deleted |
| 130, 143 | SIGINT or SIGTERM interrupted the delete; it released the library |
