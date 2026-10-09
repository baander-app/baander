# app:artist:album:role

Change the role of an artist's credit on an album. The command does what `PATCH /api/artists/{publicId}/albums/{albumId}` does in the admin API, through the same use case.

## Quick start

```bash
make exec cmd="php bin/console app:artist:album:role V1StGXR8_Z5jdHi6B-myT 0192a3b4-c5d6-7890-abcd-ef1234567890 producer"
```

When the artist holds several roles on the album, name the one to change:

```bash
make exec cmd="php bin/console app:artist:album:role V1StGXR8_Z5jdHi6B-myT 0192a3b4-c5d6-7890-abcd-ef1234567890 producer --from=featured"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The public ID of the artist |
| `album-id` | Yes | The album UUID, the `uuid` field of the album in the API |
| `role` | Yes | The new role: `primary`, `featured`, `producer`, `composer`, `conductor`, `remixer`, `djmix` or `other` |

## Options

| Option | Description |
|--------|-------------|
| `--from=ROLE` | The role of the credit to change. Required when the artist holds several roles on the album; the API takes it as `currentRole` |
| `--json` | Print nothing on success, as the admin API answers `204 No Content`; the exit code reports the outcome |

## Details

Without `--from` the command changes the artist's only credit on the album. When the artist holds several roles there, it refuses with a message that lists them, and changes nothing.

Changing a credit to a role the artist already holds on the album removes the changed credit, because an artist holds each role on an album once. For example, with `featured` and `producer` credits, `producer --from=featured` leaves a single `producer` credit. Changing a credit to its own role succeeds without change.

When the artist has no credit on the album, or none with the `--from` role, the command fails as the API does with `404`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The credit has the new role |
| 1 | No artist has the public ID, the artist has no such credit on the album, or another error occurred; the message says why |
| 2 | The public ID or the album ID is malformed, a role is not one of the listed roles, or `--from` is missing while the artist holds several roles on the album |
