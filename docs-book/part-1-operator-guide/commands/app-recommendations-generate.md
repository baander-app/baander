# app:recommendations:generate

Generate music recommendations using all available strategies. Dispatches a generation command onto the message bus, where registered recommendation strategies run asynchronously.

## Quick start

Full regeneration for all users:

```bash
make exec cmd="php bin/console app:recommendations:generate"
```

Incremental run for a single user:

```bash
make exec cmd="php bin/console app:recommendations:generate --mode incremental --user-id 0192f3c4-..."
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--mode` (`-m`) | `full` | Generation mode: `full` (clear and regenerate) or `incremental` (only new/modified content) |
| `--user-id` (`-u`) | — | Generate for a specific user (UUID). If omitted, generates for all users. |

## Details

The command validates `--mode` and parses `--user-id` as a UUID before dispatching. It dispatches a `GenerateRecommendationsCommand` onto the command bus; the actual strategy work runs in the message handler, not inline. The reported duration covers only the dispatch path — strategy runtime happens after the console command returns.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Generation command dispatched successfully |
| 1 | Invalid `--mode` value, invalid `--user-id` UUID format, or dispatch threw |

## Tips

- Use `--mode incremental` for routine refreshes; reserve `full` for when the underlying data or strategies change.
- Scope a regeneration to one user with `--user-id` when investigating per-user results.
- The command returns once dispatched — monitor the worker queue for actual completion.
