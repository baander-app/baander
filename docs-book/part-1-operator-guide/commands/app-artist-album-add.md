# app:artist:album:add

Credit an artist on an album with a role. The command does what `POST /api/artists/{publicId}/albums` does in the admin API, through the same use case.

## Quick start

```bash
make exec cmd="php bin/console app:artist:album:add V1StGXR8_Z5jdHi6B-myT 0192a3b4-c5d6-7890-abcd-ef1234567890 featured"
```

In a script that reads only the exit code:

```bash
make exec cmd="php bin/console app:artist:album:add V1StGXR8_Z5jdHi6B-myT 0192a3b4-c5d6-7890-abcd-ef1234567890 featured --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The public ID of the artist |
| `album-id` | Yes | The album UUID, the `uuid` field of the album in the API |
| `role` | Yes | `primary`, `featured`, `producer`, `composer`, `conductor`, `remixer`, `djmix` or `other` |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

An artist may hold several roles on one album, such as `featured` and `producer`; each is a separate credit. Adding a credit the artist already has succeeds and leaves one credit, so a retry is harmless. An album UUID that names no album fails with `Album "<id>" not found.`, which the API answers with `404`.

To change a credit's role, use [app:artist:album:role](app-artist-album-role.md); to remove the artist from the album, use [app:artist:album:remove](app-artist-album-remove.md). [app:artist:song:add](app-artist-song-add.md) credits an artist on a song.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The artist has the credit |
| 1 | No artist has the public ID, no album has the album ID, or another error occurred; the message says why |
| 2 | The public ID or the album ID is malformed, or the role is not one of the listed roles |
