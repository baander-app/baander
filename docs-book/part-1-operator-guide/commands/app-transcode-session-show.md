# app:transcode:session:show

Show one transcode session: its owner, job, video, state, priority, audio profile and progress. The command shows what `GET /api/transcode/sessions/{uuid}` returns, which the admin **Transcode** page calls for a session's details.

## Quick start

```bash
make exec cmd="php bin/console app:transcode:session:show 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `uuid` | Yes | UUID of the session, as [app:transcode:session:list](app-transcode-session-list.md) prints it |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the session as the API returns it, as a JSON object on stdout |

## Details

The API shows a session only to its owner and to administrators. The shell has full authority and shows any session, finished ones included.

The command prints the session's UUID, public ID, user, job, video, state, priority, audio profile name, current segment and wall-clock offset, and when it was created and last updated, in ISO 8601 format. With `--json` it prints every field of the API response, including the full audio profile and the session metrics.

The command is read-only.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Session shown |
| 1 | No session has this UUID |
| 2 | The argument is not a UUID |
