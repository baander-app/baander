# app:library:member:revoke

Stop a user seeing a library. The command runs the same use case as clearing a library in the **Library access** dialog on the admin **Users** page (`DELETE /api/admin/users/{userId}/libraries/{libraryId}` in the API).

## Quick start

```bash
make exec cmd="php bin/console app:library:member:revoke listener@baander.app my-music"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `user` | Yes | The user, by email address or UUID, as the `app:user:*` commands take it |
| `library` | Yes | The library's UUID or slug |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--json` | — | Print only the library with its access in JSON, as the API's `data` returns it |

## Details

The user's next request no longer shows the library's media, and they stop receiving its scan-completed notifications. Two things the revocation does not reach:

- Signed video segment URLs issued before it stay valid until they expire, up to 24 hours later.
- Items the user already queued in a party stay in the queue.

Revoking access the user lacks succeeds and changes nothing. Administrators see every library whatever their grants, so revoking an administrator's access hides nothing from them. In the API, only super admins may revoke access.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The user may no longer see the library |
| 1 | No user has the email address or UUID, no library has the UUID or slug, or another error occurred; the message says why |
