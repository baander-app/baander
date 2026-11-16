# app:artist:song:add

Credit an artist on a song with a role. The command does what `POST /api/artists/{publicId}/songs` does in the admin API, through the same use case.

## Quick start

```bash
make exec cmd="php bin/console app:artist:song:add V1StGXR8_Z5jdHi6B-myT aB3dE5fG7hJ9kL1mN3pQr featured"
```

In a script that reads only the exit code:

```bash
make exec cmd="php bin/console app:artist:song:add V1StGXR8_Z5jdHi6B-myT aB3dE5fG7hJ9kL1mN3pQr featured --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The public ID of the artist |
| `song-id` | Yes | The public ID of the song, the `publicId` field of the song in the API |
| `role` | Yes | `primary`, `featured`, `producer`, `composer`, `conductor`, `remixer`, `djmix` or `other` |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

An artist may hold several roles on one song, such as `featured` and `producer`; each is a separate credit. A new credit stores the artist's name at that moment as its credited name; renaming the artist afterwards does not change it. Adding a credit the artist already has succeeds and leaves that one credit as it was, credited name included, so a retry is harmless. A song public ID that names no song fails with `Song "<id>" not found.`, which the API answers with `404`.

The change runs in one transaction. It waits for a MusicBrainz match, an import, an artist merge or removal, an album merge or another credit change that holds the same artist or song, and then acts on what that change left. An artist or song deleted while the change waited fails as an unknown one does. When the database ends the change to break a deadlock, or the artist took another name or MusicBrainz ID while the change waited, nothing is written and the command fails with `Another change to the same artist, song or album ran at the same time. Make the change again.`, which the API answers with `409` and the reason `credit_busy`. Making the change again may succeed.

Adding a credit to a song whose credits a MusicBrainz match set also records, in the same transaction, that the song's credits were changed by hand, so the next retag replaces them with the credits of the tags; see [Retagged files](app-library-scan.md#retagged-files). Adding a credit the song holds already records nothing.

To change a credit's role, use [app:artist:song:role](app-artist-song-role.md); to remove the artist from the song, use [app:artist:song:remove](app-artist-song-remove.md). [app:artist:album:add](app-artist-album-add.md) credits an artist on an album.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The artist has the credit |
| 1 | No artist has the public ID, no song has the song ID, another change to the same artist or song ran at the same time, or another error occurred; the message says why |
| 2 | The public ID or the song ID is malformed, or the role is not one of the listed roles |
