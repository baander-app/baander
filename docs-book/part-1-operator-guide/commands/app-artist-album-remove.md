# app:artist:album:remove

Remove every one of an artist's credits on an album, whatever their roles. The command does what `DELETE /api/artists/{publicId}/albums/{albumId}` does in the admin API, through the same use case.

## Quick start

```bash
make exec cmd="php bin/console app:artist:album:remove V1StGXR8_Z5jdHi6B-myT aB3dE5fG7hJ9kL1mN3pQr"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The public ID of the artist |
| `album-id` | Yes | The public ID of the album, the `publicId` field of the album in the API |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

Only the credits are removed; the artist and the album stay. When the artist has no credit on the album, the command fails with `Artist "<public id>" has no credit on album "<id>".`, which the API answers with `404`. Running it a second time therefore exits 1.

The change runs in one transaction. It waits for a MusicBrainz match, an import, an artist merge or removal, an album merge or another credit change that holds the same artist or album, and then acts on what that change left. An artist or album deleted while the change waited fails as an unknown one does. When the database ends the change to break a deadlock, or the artist took another name or MusicBrainz ID while the change waited, nothing is written and the command fails with `Another change to the same artist, song or album ran at the same time. Make the change again.`, which the API answers with `409` and the reason `credit_busy`. Making the change again may succeed.

To change one role and keep the others, use [app:artist:album:role](app-artist-album-role.md). To restore a credit, use [app:artist:album:add](app-artist-album-add.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The artist is no longer credited on the album |
| 1 | No artist has the public ID, the artist has no credit on the album, another change to the same artist or album ran at the same time, or another error occurred; the message says why |
| 2 | The public ID or the album ID is malformed |
