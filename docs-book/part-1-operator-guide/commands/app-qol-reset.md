# app:qol:reset

Discard the stream governor's learning data in every worker of the running web server and return each worker to the Learning state, in which every stream is admitted while the model gathers new samples. The command does what `POST /api/admin/qol/reset` does in the admin API. Both change every worker through the server's control socket.

## Quick start

```bash
make exec cmd="php bin/console app:qol:reset --force"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:qol:reset --force --json"
```

## Options

| Option | Description |
|--------|-------------|
| `--force` | Reset without asking; required when no terminal is attached |
| `--json` | Print only the per-worker report, as the admin API's `data` payload, in JSON |

## Details

Run the command in the web container: it talks to the web server running there. On a terminal the command asks for confirmation first. Without a terminal it refuses to run unless `--force` is given, and exits with code 2.

Each worker empties its learning model, returns to the Learning state and forgets its active streams. The reset is saved to `var/qol_state/governor_state.json` at once, so a server reload does not bring the old model back. The algorithm profile is not changed.

The command prints each worker's status after the reset, in the table `app:qol:status` prints. A worker that does not answer within two seconds is named on stderr, as is a worker where the reset failed, and the command exits with code 1. The admin API answers 503 in the same case.

When no web server runs in the container, the command prints `no web server is running in this container` and exits with code 1.

With `--json` the command prints only the per-worker report the API returns as `data`, with the same fields as [app:qol:status](app-qol-status.md) `--json`. When a worker does not answer or fails, the command still prints the report, names the workers on stderr and exits with 1; the API answers `503` with the same report in `details`. `--json` does not stand in for `--force`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Every worker was reset and the reset was saved |
| 1 | No web server is running, a worker did not answer or failed, the operator declined, or the server could not be reached; the message says why |
| 2 | No terminal is attached and `--force` was not given; nothing was changed |
