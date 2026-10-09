# app:rate-limiter:clear

Clear the stored state of one rate limiter, or of every rate limiter with `--all`. Use it to unblock rate-limited clients after a configuration change or during an incident. The command does what `DELETE /api/monitor/rate-limiters/{name}/clear` and `DELETE /api/monitor/rate-limiters/clear` do in the admin API.

## Quick start

```bash
make exec cmd="php bin/console app:rate-limiter:clear auth_login_ip"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `name` | Without `--all` | The limiter to clear, as [app:rate-limiter:list](app-rate-limiter-list.md) shows it |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--all` | off | Clear every configured rate limiter |
| `--json` | off | Print the result as the admin API returns it, in JSON |

With `--json`, the command prints the API's `data` payload and nothing else on stdout: `{"cleared": true, "limiter": "auth_login_ip"}` for one limiter, or `{"cleared": true, "limiters": ["auth_login_ip", "..."]}` with `--all`. Errors go to stderr.

```bash
make exec cmd="php bin/console app:rate-limiter:clear auth_login_ip --json"
```

## Details

Pass either a limiter name or `--all`, not both. Each limiter keeps its state in its own Redis-backed cache pool (`cache.rate_limiter.<name>`), so clearing one limiter leaves every other limiter's state intact. Clearing resets the counters of every client of that limiter at once; it cannot clear a single client.

The command clears immediately and does not ask for confirmation. The admin API requires `?confirm=true` instead.

With `--all`, the command tries every pool and then reports the limiters it could not clear. On success it prints the names of the cleared limiters.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | State cleared |
| 1 | The limiter name is unknown, or a cache pool could not be cleared; the message says which |
| 2 | Neither a name nor `--all` was given, or both were |
