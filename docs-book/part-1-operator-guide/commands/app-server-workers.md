# app:server:workers

Show the worker pools of the running web server: HTTP workers and user processes. The command shows what `GET /api/debug/workers` returns in the admin API and what the Worker pools section of the admin Server Diagnostics page displays. Both read the server through its control socket.

## Quick start

```bash
make exec cmd="php bin/console app:server:workers"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the data as the admin API returns it, in JSON |

## Details

Run the command in the web container: it talks to the web server running there. These figures are server-wide, so one worker answers for the whole server. The command prints one table per pool:

| Section | Figures |
|---------|---------|
| HTTP workers | Total, idle and active workers, requests and dispatches handled, connections, coroutines, start time, and bytes received and sent |
| User workers | Number of user processes added to the server |


The web server has no background task or transcoding pool. This command does not
report the independent worker deployment. Host deployment `status` reports
committed observations with readiness `not_checked`; it is not an application
readiness probe.

With `--json`, stdout holds the API response.

When no web server runs in the container, the command prints `no web server is running in this container` and exits with code 1.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Figures printed |
| 1 | No web server is running or the server could not answer; the message says why |
