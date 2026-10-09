# app:album:delete

Delete an album and every song on it, and optionally the songs' audio files. The command does what `DELETE /api/admin/albums/{publicId}` does in the admin API, through the same use case. `DELETE /api/albums/{publicId}` runs the same delete with the defaults. With `--dry-run` it shows what `GET /api/admin/albums/{publicId}/delete-preview` shows.

## Quick start

Preview the delete, including a check of each audio file:

```bash
make exec cmd="php bin/console app:album:delete V1StGXR8_Z5jdHi6B-myT --dry-run --delete-files"
```

Delete the album and its songs from the catalog and leave the audio files on disk:

```bash
make exec cmd="php bin/console app:album:delete V1StGXR8_Z5jdHi6B-myT"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The album's public ID, as the API and the web app show it |

## Options

| Option | Description |
|--------|-------------|
| `--delete-files` | Also delete the songs' audio files from disk, inside the library root only; requires `--force` |
| `--keep-cover` | Keep the cover image; by default it is deleted with the album |
| `--dry-run` | Print what the delete would remove and change nothing; with `--delete-files`, check each file |
| `--force` | Delete without asking; required when no terminal is attached, and always required with `--delete-files` |
| `--json` | Print the API response's `data` payload: the delete result, or with `--dry-run` the preview |

## Details

The command deletes every song on the album, however many there are.

On a terminal the command asks before it deletes anything. Without a terminal and without `--force` it deletes nothing and exits with code 2. `--delete-files` needs `--force` even on a terminal, so a confirmation typed out of habit cannot delete audio files. `--json` does not stand in for `--force`.

With `--delete-files`, every song file is checked before anything changes. The command refuses the whole delete and deletes nothing when:

- a file, or the file a symlink points to, lies outside the album's library root (exit code 2);
- the server cannot write a directory that holds a file (exit code 1);
- a scan of the library is running (exit code 1);
- the library folder is not available, such as unmounted storage (exit code 1).

The album, its songs and the songs' file index entries are then deleted together, and the files are unlinked after that. A symlink is removed as a link; its target stays. The result lists the files removed, the files that were already missing, and the files left on disk with the reason. A file left on disk has no index entry, so the next library scan imports it again. When any file is left, the command exits with code 1 although the catalog rows are gone.

Without `--delete-files` the audio files stay on disk and keep their index entries, so an incremental scan does not import them again until they change.

The cover image record, file and derived files are deleted after the album, unless `--keep-cover` is given.

`--dry-run` shows the album, its song count and file size, the cover, and the playlists that lose songs. With `--delete-files` it also lists the verdict for each file (`deletable`, `missing`, `outside_root` or `directory_not_writable`) and whether a scan holds the library. It needs no `--force`.

To delete one song, use [app:song:delete](app-song-delete.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Album deleted, or the preview printed |
| 1 | No album has the public ID, a scan holds the library, the library folder is not available, the server cannot write a song directory, a file was left on disk, the operator declined, or another error occurred; the message says why |
| 2 | The public ID is malformed, a song file lies outside the library root, `--delete-files` was given without `--force`, or no terminal is attached and `--force` was not given; nothing was deleted |
