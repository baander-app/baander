# app:recommendations:generate

Generate music recommendations using all available strategies. The command runs the generation itself and returns when it has finished.

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

The command validates `--mode` and parses `--user-id` as a UUID before dispatching. It dispatches a `GenerateRecommendationsCommand` onto the command bus. That command has no transport route, and the CPU process pool does not run in a console process, so the handler runs every strategy inline in the command's own process. The reported duration covers the whole generation. An incremental run recomputes recommendations for songs updated in the last seven days; a full run loads every song.

The command always generates, whatever the `recommendations.auto_generate` [server setting](../configuration.md#server-settings) says. That setting governs only the daily **Generate recommendations** scheduled job; see [Scheduled recommendations](../configuration.md#scheduled-recommendations).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Generation finished |
| 1 | Invalid `--mode` value, invalid `--user-id` UUID format, or generation threw |

## Tips

- Use `--mode incremental` for routine refreshes; reserve `full` for when the underlying data or strategies change.
- Scope a regeneration to one user with `--user-id` when investigating per-user results.
- A full run on a large library can take long and use a lot of memory, because it loads every song and every user's listening history.
