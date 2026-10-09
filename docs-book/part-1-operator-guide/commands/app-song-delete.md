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

With `--delete-files`, the file is checked before anything changes. The command refuses the delete and deletes nothing when the file, or the file a symlink points to, lies outside the library root (exit code 2), when the server cannot write its directory (exit code 1), when a scan of the library is running (exit code 1), or when the library folder is not available, such as unmounted storage (exit code 1).

The song and its file index entry are then deleted together, and the file is unlinked after that. A symlink is removed as a link; its target stays. When the file cannot be removed, the song is still deleted, the result lists the file as left with the reason, and the command exits with code 1. The next library scan imports a file left on disk again.

Without `--delete-files` the audio file stays on disk and keeps its index entry, so an incremental scan does not import it again until it changes.

`--dry-run` shows the song, its album, its file and size, and the playlists that lose it. With `--delete-files` it also shows the file's verdict (`deletable`, `missing`, `outside_root` or `directory_not_writable`) and whether a scan holds the library. It needs no `--force`.

To delete a whole album, use [app:album:delete](app-album-delete.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Song deleted, or the preview printed |
| 1 | No song has the public ID, a scan holds the library, the library folder is not available, the server cannot write the song directory, the file was left on disk, the operator declined, or another error occurred; the message says why |
| 2 | The public ID is malformed, the file lies outside the library root, `--delete-files` was given without `--force`, or no terminal is attached and `--force` was not given; nothing was deleted |
