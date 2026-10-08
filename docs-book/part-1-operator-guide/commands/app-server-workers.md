# app:server:workers

Show the worker pools of the running web server: HTTP workers, task workers, user processes and the transcoding process pool. The command shows what `GET /api/debug/workers` returns in the admin API and what the Worker pools section of the admin Server Diagnostics page displays. Both read the server through its control socket.

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
| Task workers | Total, idle and active task workers, tasks in progress and tasks handled |
| User workers | Number of user processes added to the server |
| Transcoding pool | Whether the pool is available and running, its worker count and the size of its result table |

With `--json`, stdout holds the API response.

When no web server runs in the container, the command prints `no web server is running in this container` and exits with code 1.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Figures printed |
| 1 | No web server is running or the server could not answer; the message says why |
