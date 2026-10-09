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
| `song-id` | Yes | The song UUID, the `uuid` field of the song in the API |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

Only the credits are removed; the artist and the song stay. When the artist has no credit on the song, the command fails with `Artist "<public id>" has no credit on song "<id>".`, which the API answers with `404`. Running it a second time therefore exits 1.

To change one role and keep the others, use [app:artist:song:role](app-artist-song-role.md). To restore a credit, use [app:artist:song:add](app-artist-song-add.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The artist is no longer credited on the song |
| 1 | No artist has the public ID, the artist has no credit on the song, or another error occurred; the message says why |
| 2 | The public ID or the song ID is malformed |
