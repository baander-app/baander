# app:server:stats

Show the memory, process and coroutine figures of every worker of the running web server, together with the Redis and server-sent events (SSE) figures they share. The command shows what `GET /api/debug/stats` returns in the admin API and what the admin Server Diagnostics page displays. Both read the workers through the server's control socket.

## Quick start

```bash
make exec cmd="php bin/console app:server:stats"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the data as the admin API returns it, in JSON |

## Details

Run the command in the web container: it talks to the web server running there. The first table has one row per HTTP worker:

| Column | Content |
|--------|---------|
| Worker | The Swoole worker ID |
| PID | The worker's process ID |
| Memory (MB) | Memory in use by PHP |
| Peak (MB) | Highest memory in use by PHP since the worker started |
| Real (MB), Real peak (MB) | Memory allocated from the system, now and at its peak |
| Coroutines | Coroutines running in the worker |
| Coroutine peak | Most coroutines the worker has run at once |

The Redis section shows whether Redis answers, its key count, connected clients and memory. The SSE section shows the number of open server-sent event connections across the server. Both are read once, not per worker.

A worker that does not answer within two seconds is named on stderr, as is a worker whose figures could not be read. The other workers' rows are still printed, and the command exits with code 1.

With `--json`, stdout holds only the `data` object of the API response: `workers`, `missing_workers`, `worker_errors`, `redis` and `sse`.

When no web server runs in the container, the command prints `no web server is running in this container` and exits with code 1.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Every worker answered and the figures were printed |
| 1 | No web server is running, a worker did not answer or failed, or the server could not be reached; the message says why |
