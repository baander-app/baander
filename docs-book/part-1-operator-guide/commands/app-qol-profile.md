# app:qol:profile

Set the stream governor's algorithm profile, which decides how much CPU the running web server lets streams use. The command does what `PATCH /api/admin/qol/profile` does in the admin API. Both change every worker through the server's control socket.

## Quick start

```bash
make exec cmd="php bin/console app:qol:profile balanced"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:qol:profile balanced --json"
```

## Arguments

| Argument | Description |
|----------|-------------|
| `profile` | `conservative` (70% budget cap), `balanced` (80%) or `aggressive` (90%) |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print only the per-worker report, as the admin API's `data` payload, in JSON |

## Details

Run the command in the web container: it talks to the web server running there. The profile is saved to `var/qol_state/algorithm_profile.json` and held in memory that every HTTP worker shares, so it stays in force after a server reload or restart. Saving the learning model never writes the profile.

The command prints each worker's status after the change, in the table `app:qol:status` prints, and confirms when every worker uses the new profile. Setting the profile already in force succeeds.

An unknown profile name changes nothing and exits with code 2.

A worker that does not answer within two seconds is named on stderr, as is a worker where the change failed, and the command exits with code 1. The admin API answers 503 in the same case. The profile is shared, so a worker that did not answer still reads the new one; run `app:qol:status` to check.

When no web server runs in the container, the command prints `no web server is running in this container` and exits with code 1.

With `--json` the command prints only the per-worker report the API returns as `data`, with the same fields as [app:qol:status](app-qol-status.md) `--json`. When a worker does not answer or fails, the command still prints the report, names the workers on stderr and exits with 1; the API answers `503` with the same report in `details`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Every worker uses the new profile |
| 1 | No web server is running, a worker did not answer or failed, or the server could not be reached; the message says why |
| 2 | Unknown profile name; nothing was changed |
