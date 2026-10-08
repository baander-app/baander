# app:server:coroutines

Show the coroutine and channel statistics of every worker of the running web server. The command shows what `GET /api/debug/coroutines` returns in the admin API and what the Coroutines section of the admin Server Diagnostics page displays. Both read the workers through the server's control socket.

## Quick start

```bash
make exec cmd="php bin/console app:server:coroutines"
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
| Coroutines | Coroutines running in the worker |
| Peak | Most coroutines the worker has run at once |
| Last CID | The most recently assigned coroutine ID |
| Active CIDs | Number of coroutines alive when the worker answered |
| Channels | Number of tracked coroutine channels, such as transcode seek signals |

When a worker has tracked channels, a second table lists each one with its queue length, capacity, waiting consumers and producers, and whether it is closed.

A worker that does not answer within two seconds is named on stderr, as is a worker whose statistics could not be read. The other workers' rows are still printed, and the command exits with code 1.

With `--json`, stdout holds the API response: `workers`, `missing_workers` and `worker_errors`.

When no web server runs in the container, the command prints `no web server is running in this container` and exits with code 1.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Every worker answered and the statistics were printed |
| 1 | No web server is running, a worker did not answer or failed, or the server could not be reached; the message says why |
