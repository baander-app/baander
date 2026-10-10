# app:library:member:grant

Let a user see a library. The command runs the same use case as ticking a library in the **Library access** dialog on the admin **Users** page (`PUT /api/admin/users/{userId}/libraries/{libraryId}` in the API).

## Quick start

```bash
make exec cmd="php bin/console app:library:member:grant listener@baander.app my-music"
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

From the user's next request on, the library's albums, songs and other media appear in their lists and searches, and they receive its scan-completed notifications. Administrators see every library without a grant.

Granting access the user already has succeeds and changes nothing. [app:library:member:list](app-library-member-list.md) shows a user's access to every library; [app:library:member:revoke](app-library-member-revoke.md) takes it away. In the API, only super admins may grant access.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The user may see the library |
| 1 | No user has the email address or UUID, no library has the UUID or slug, or another error occurred; the message says why |
