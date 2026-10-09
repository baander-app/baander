# app:artist:song:add

Credit an artist on a song with a role. The command does what `POST /api/artists/{publicId}/songs` does in the admin API, through the same use case.

## Quick start

```bash
make exec cmd="php bin/console app:artist:song:add V1StGXR8_Z5jdHi6B-myT 0192a3b4-c5d6-7890-abcd-ef1234567890 featured"
```

In a script that reads only the exit code:

```bash
make exec cmd="php bin/console app:artist:song:add V1StGXR8_Z5jdHi6B-myT 0192a3b4-c5d6-7890-abcd-ef1234567890 featured --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The public ID of the artist |
| `song-id` | Yes | The song UUID, the `uuid` field of the song in the API |
| `role` | Yes | `primary`, `featured`, `producer`, `composer`, `conductor`, `remixer`, `djmix` or `other` |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

An artist may hold several roles on one song, such as `featured` and `producer`; each is a separate credit. Adding a credit the artist already has succeeds and leaves one credit, so a retry is harmless. A song UUID that names no song fails with `Song "<id>" not found.`, which the API answers with `404`.

To change a credit's role, use [app:artist:song:role](app-artist-song-role.md); to remove the artist from the song, use [app:artist:song:remove](app-artist-song-remove.md). [app:artist:album:add](app-artist-album-add.md) credits an artist on an album.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The artist has the credit |
| 1 | No artist has the public ID, no song has the song ID, or another error occurred; the message says why |
| 2 | The public ID or the song ID is malformed, or the role is not one of the listed roles |
