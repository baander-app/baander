# app:qol:streams

List the streams each worker of the running web server has admitted, with the cost the stream governor predicted for each. The command shows what `GET /api/admin/qol/streams` returns in the admin API. Both read the workers through the server's control socket.

## Quick start

```bash
make exec cmd="php bin/console app:qol:streams"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the data as the admin API returns it, in JSON |

## Details

Run the command in the web container: it talks to the web server running there. The table has one row per HTTP worker and a total row:

| Column | Content |
|--------|---------|
| Worker | The Swoole worker ID |
| Active streams | Streams the worker has admitted and not yet released |
| Streams | One line per stream: the transcode job ID, the quality tier and the predicted cost in percent of CPU |

The total row adds up the active streams and their predicted cost across workers. A stream is held by the worker that admitted it, so a server reload clears every worker's list.

A worker that does not answer within two seconds is named on stderr, as is a worker whose streams could not be read. The other workers' rows are still printed, and the command exits with code 1.

With `--json`, stdout holds only the `data` object of the API response: `workers`, `total`, `missing_workers` and `worker_errors`.

When no web server runs in the container, the command prints `no web server is running in this container` and exits with code 1.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Every worker answered and the streams were printed |
| 1 | No web server is running, a worker did not answer or failed, or the server could not be reached; the message says why |
