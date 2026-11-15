# app:artist:song:remove

Remove every one of an artist's credits on a song, whatever their roles. The command does what `DELETE /api/artists/{publicId}/songs/{songId}` does in the admin API, through the same use case.

## Quick start

```bash
make exec cmd="php bin/console app:artist:song:remove V1StGXR8_Z5jdHi6B-myT 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The public ID of the artist |
| `song-id` | Yes | The public ID of the song, the `publicId` field of the song in the API |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

Only the credits are removed; the artist and the song stay. When the artist has no credit on the song, the command fails with `Artist "<public id>" has no credit on song "<id>".`, which the API answers with `404`. Running it a second time therefore exits 1.

The change runs in one transaction. It waits for a MusicBrainz match, an import, an artist merge or removal, an album merge or another credit change that holds the same artist or song, and then acts on what that change left. An artist or song deleted while the change waited fails as an unknown one does. When the database ends the change to break a deadlock, or the artist took another name or MusicBrainz ID while the change waited, nothing is written and the command fails with `Another change to the same artist, song or album ran at the same time. Make the change again.`, which the API answers with `409` and the reason `credit_busy`. Making the change again may succeed.

On a song whose credits a MusicBrainz match set, the removal also records, in the same transaction, that the song's credits were changed by hand, so the next retag replaces them with the credits of the tags; see [Retagged files](app-library-scan.md#retagged-files).

To change one role and keep the others, use [app:artist:song:role](app-artist-song-role.md). To restore a credit, use [app:artist:song:add](app-artist-song-add.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The artist is no longer credited on the song |
| 1 | No artist has the public ID, the artist has no credit on the song, another change to the same artist or song ran at the same time, or another error occurred; the message says why |
| 2 | The public ID or the song ID is malformed |
