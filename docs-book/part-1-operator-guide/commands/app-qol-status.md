# app:qol:status

Show the stream governor's status in every worker of the running web server: its learning state, algorithm profile, active streams and learning model. The command shows what `GET /api/admin/qol/status` returns in the admin API. Both read the workers through the server's control socket.

## Quick start

```bash
make exec cmd="php bin/console app:qol:status"
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
| State | `learning` while the model gathers samples, `active` once it predicts stream cost |
| Profile | The algorithm profile: `conservative`, `balanced` or `aggressive` |
| Active streams | Streams the worker has admitted and not yet released; the total row adds them up |
| Samples | Utilization samples in the worker's learning model |
| Model ready | `yes` when the model has enough samples to predict cost |
| Budget cap | The share of CPU the profile lets streams use |

Each worker admits streams against its own active streams, so the counts differ between workers. The profile is shared: every worker reports the same one.

A worker that does not answer within two seconds is named on stderr, as is a worker whose status could not be read. The other workers' rows are still printed, and the command exits with code 1.

With `--json`, stdout holds only the `data` object of the API response: `workers`, `total`, `missing_workers` and `worker_errors`.

When no web server runs in the container, the command prints `no web server is running in this container` and exits with code 1.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Every worker answered and the status was printed |
| 1 | No web server is running, a worker did not answer or failed, or the server could not be reached; the message says why |
