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
- a scan, or another delete with files, holds the library (exit code 1);
- the library folder is not available, such as unmounted storage (exit code 1);
- every song file is missing, as when the storage under part of the library is not mounted (exit code 1, reason `all_files_missing`). An album without songs is not refused for this. When the files are really gone, delete the album without `--delete-files`.

The album, its songs and the songs' file index entries are then deleted together, and the files are unlinked after that. A symlink is removed as a link; its target stays. The result lists the files removed, the files that were already missing, and the files left on disk with the reason (`outside_root`, `unlink_failed` or `claim_lost`). A file left on disk has no index entry, so the next library scan imports it again. When any file is left, the command exits with code 1 although the catalog rows are gone.

A delete with `--delete-files` holds the album's library from the first check until it ends, so no scan indexes the files it removes. A scan started meanwhile, from the web or with [app:library:scan](app-library-scan.md), is refused with reason `library_busy` naming the delete. The library's scan status and last scan time stay as they were. The delete releases the library when it ends, also when it fails or SIGINT or SIGTERM interrupts it; an interrupted delete exits with 128 plus the signal number. Further signals while the interrupted delete releases the library are ignored. A signal that arrives as the delete is ending can come too late to stop it: the delete then finishes, and the command prints its result with a warning that names the signal. The hold is a 15-minute lease that the delete renews while it unlinks. A process killed outright leaves it to lapse, or `app:library:scan <library> --release --force` releases it. A delete that went past its lease without renewing it, while a scan or another delete took the library over, stops unlinking and lists the remaining files as left with `claim_lost`. A delete that finds the lapsed hold of a scan that died takes the library over and marks that scan failed.

The imports a scan queued for its workers wait while a delete with files holds the library, and skip the files that are gone once it ends, so a deleted song does not come back from an import that was already queued. The delete waits for the songs an import is writing at that moment and deletes them with the album; the import then writes no more songs until the delete ends, and imports the rest of its files after it. When an import skips a file that is gone, the next scan imports the file if it comes back.

The last check of a file runs immediately before it is unlinked. PHP has no `unlinkat()`, so a parent directory replaced with a symlink in the moment between that check and the unlink is not caught, and the unlink follows the symlink. Only someone who can write the library's directories can do this; it is an accepted limit.

Without `--delete-files` the audio files stay on disk and keep their index entries, so an incremental scan does not import them again until they change.

The cover image record, file and derived files are deleted after the album, unless `--keep-cover` is given.

`--dry-run` shows the album, its song count and file size, the cover, and the playlists that lose songs. With `--delete-files` it also lists the verdict for each file (`deletable`, `missing`, `outside_root` or `directory_not_writable`) and whether a scan or another delete holds the library. It needs no `--force`.

To delete one song, use [app:song:delete](app-song-delete.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Album deleted, or the preview printed |
| 1 | No album has the public ID, a scan or another delete holds the library, the library folder is not available, every song file is missing, the server cannot write a song directory, a file was left on disk, the operator declined, or another error occurred; the message says why |
| 2 | The public ID is malformed, a song file lies outside the library root, `--delete-files` was given without `--force`, or no terminal is attached and `--force` was not given; nothing was deleted |
| 130, 143 | SIGINT or SIGTERM interrupted the delete; it released the library (a signal that came too late to stop the delete prints a warning, and the exit code follows the result) |
