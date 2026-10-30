# app:rate-limiter:clear

Clear the stored state of one rate limiter, or of every rate limiter with `--all`. Use it to unblock rate-limited clients after a configuration change or during an incident. The command does what `DELETE /api/monitor/rate-limiters/{name}/clear` and `DELETE /api/monitor/rate-limiters/clear` do in the admin API.

## Quick start

The Make examples below target the checkout's development preview. For an
installed instance, use the `dc` function from
[Getting started](../getting-started.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec app php bin/console …`.

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

Pass either a limiter name or `--all`, not both. Each limiter has its own Redis
storage namespace. Clearing advances that namespace's generation, so every client
of the named limiter starts with a fresh budget. It leaves other limiters intact;
the command cannot clear a single client.

A clear does not scan or delete existing subject keys. They expire naturally or
are replaced when that subject is next read. A consumer that read the previous
generation cannot commit against the cleared state; it must retry against the new
generation. Redis must acknowledge the generation change before the command
reports success.

A successful clear is a budget reset, not a general repair of corrupt Redis keys.
A subject key of the wrong Redis type, or a subject hash with a missing or invalid
generation field, can still cause `503` after a named clear. Such state needs its
TTL to expire, if one exists, or targeted repair. A valid old-generation hash is
replaced on its next read, including any extra fields. The internal per-subject
reset also replaces the subject record; this command does not expose that operation.

Changing a limiter's effective configuration starts fresh subject budgets because
the configuration contributes to their storage identities. Named clear still
reaches every configuration under that limiter name.

The command clears immediately and does not ask for confirmation. The admin API requires `?confirm=true` instead.

With `--all`, the command tries every configured namespace sequentially, including
after a failure, then reports any limiters it could not clear. Successful clears
remain in effect when another limiter fails; the operation does not roll them
back. On complete success it prints the names of the cleared limiters.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | State cleared |
| 1 | The limiter name is unknown, or a storage namespace could not be cleared; the message says which |
| 2 | Neither a name nor `--all` was given, or both were |
