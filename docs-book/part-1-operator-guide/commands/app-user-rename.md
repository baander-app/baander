# app:user:rename

Change a user's display name. The command runs the same use case as changing the name in the admin panel's user editor (`PATCH /api/admin/users/{id}` in the admin API). That editor also changes the email address; for that, use [app:user:change-email](app-user-change-email.md).

## Quick start

```bash
make exec cmd="php bin/console app:user:rename alice@baander.app 'Alice Jensen'"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `identifier` | Yes | The user's email address or UUID |
| `name` | Yes | The new display name |

## Details

The name is stored as given, including any leading or trailing spaces. A name that is empty or only whitespace is rejected with `Name cannot be empty.`, and one longer than 255 characters with `Name cannot be longer than 255 characters.` The admin API rejects the same names with the same messages.

Renaming a user to the name they already have succeeds and changes nothing.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Name changed, or it was already the given name |
| 1 | No user has the email address or UUID, or another error occurred; the message says why |
| 2 | The name is empty or longer than 255 characters |
