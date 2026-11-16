# app:artist:song:role

Change the role of an artist's credit on a song. The command does what `PATCH /api/artists/{publicId}/songs/{songId}` does in the admin API, through the same use case.

## Quick start

```bash
make exec cmd="php bin/console app:artist:song:role V1StGXR8_Z5jdHi6B-myT aB3dE5fG7hJ9kL1mN3pQr producer"
```

When the artist holds several roles on the song, name the one to change:

```bash
make exec cmd="php bin/console app:artist:song:role V1StGXR8_Z5jdHi6B-myT aB3dE5fG7hJ9kL1mN3pQr producer --from=featured"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The public ID of the artist |
| `song-id` | Yes | The public ID of the song, the `publicId` field of the song in the API |
| `role` | Yes | The new role: `primary`, `featured`, `producer`, `composer`, `conductor`, `remixer`, `djmix` or `other` |

## Options

| Option | Description |
|--------|-------------|
| `--from=ROLE` | The role of the credit to change. Required when the artist holds several roles on the song; the API takes it as `currentRole` |
| `--json` | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

Without `--from` the command changes the artist's only credit on the song. When the artist holds several roles there, it refuses with a message that lists them, and changes nothing.

Changing a credit to a role the artist already holds on the song removes the changed credit, because an artist holds each role on a song once. For example, with `featured` and `producer` credits, `producer --from=featured` leaves a single `producer` credit. Changing a credit to its own role succeeds without change.

When the artist has no credit on the song, or none with the `--from` role, the command fails as the API does with `404`.

The change runs in one transaction. It waits for a MusicBrainz match, an import, an artist merge or removal, an album merge or another credit change that holds the same artist or song, and then acts on what that change left. An artist or song deleted while the change waited fails as an unknown one does. When the database ends the change to break a deadlock, or the artist took another name or MusicBrainz ID while the change waited, nothing is written and the command fails with `Another change to the same artist, song or album ran at the same time. Make the change again.`, which the API answers with `409` and the reason `credit_busy`. Making the change again may succeed.

On a song whose credits a MusicBrainz match set, the change also records, in the same transaction, that the song's credits were changed by hand, so the next retag replaces them with the credits of the tags; see [Retagged files](app-library-scan.md#retagged-files).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The credit has the new role |
| 1 | No artist has the public ID, the artist has no such credit on the song, another change to the same artist or song ran at the same time, or another error occurred; the message says why |
| 2 | The public ID or the song ID is malformed, a role is not one of the listed roles, or `--from` is missing while the artist holds several roles on the song |
