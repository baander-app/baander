# app:library:member:list

List every library with whether a user may see it. The command reads the same list as the **Library access** dialog on the admin **Users** page (`GET /api/admin/users/{userId}/libraries` in the API).

## Quick start

```bash
make exec cmd="php bin/console app:library:member:list listener@baander.app"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `user` | Yes | The user, by email address or UUID, as the `app:user:*` commands take it |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--json` | — | Print the list in the shape of the API's `data` array, in JSON |

## Details

The table has one row per library, in display order:

| Column | Content |
|--------|---------|
| Library | The library name |
| Slug | The slug, which [app:library:member:grant](app-library-member-grant.md) and [app:library:member:revoke](app-library-member-revoke.md) take |
| Type | The library type, such as `music` or `movie` |
| Access | `yes` when the user may see the library, otherwise `no` |

Administrators see every library whatever this list says; the grants decide what other users see. The creator of a library gets access to it when they create it in the admin panel; a library created with [app:library:create](app-library-create.md) has no members until you grant them. Library members also receive the library's scan-completed notifications.

When no library exists, the command prints `No library exists.`

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | No user has the email address or UUID, or the list could not be read; the message says why |
