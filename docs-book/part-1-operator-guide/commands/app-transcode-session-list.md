# app:transcode:session:list

List the active transcode sessions on the server. A session is active while it is pending, preparing, active or paused. The command reads the same sessions as `GET /api/transcode/sessions/`, which the admin **Transcode** page calls.

## Quick start

```bash
make exec cmd="php bin/console app:transcode:session:list"
```

Only one user's sessions:

```bash
make exec cmd="php bin/console app:transcode:session:list --user=listener@baander.app"
```

## Options

| Option | Description |
|--------|-------------|
| `--user` | List only the sessions of this user, by email address or UUID, as the `app:user:*` commands take it |
| `--json` | Print the sessions in the shape of the API's `data` array, in JSON |

## Details

The admin page and the API list only the sessions of the signed-in user, so an administrator sees only their own. The shell has no signed-in user and full authority, so without `--user` the command lists the active sessions of every user, oldest first. With `--user` it lists that user's active sessions, as the API does for them.

The table has one row per session:

| Column | Content |
|--------|---------|
| UUID | The session UUID, which [app:transcode:session:show](app-transcode-session-show.md) takes |
| User | The UUID of the user who started the session |
| Video | The video UUID |
| State | `pending`, `preparing`, `active` or `paused` |
| Priority | `critical`, `high`, `normal`, `low` or `bulk` |
| Audio | The audio profile name |
| Segment | The segment being transcoded |
| Created | Start time in ISO 8601 format |

When no session is active, the command prints `No transcode session is active.`

`--user` finds the user the same way as the `app:user:*` commands. The command checks a UUID against the user list just as it checks an email address, so a UUID that belongs to no user fails with `User "..." not found.` rather than listing no sessions. A value that is neither an email address nor a UUID fails the same way.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | No user has the email address or UUID given to `--user`, or the sessions could not be read; the message says why |
