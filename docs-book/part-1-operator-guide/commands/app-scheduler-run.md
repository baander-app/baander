# app:scheduler:run

Manually trigger a scheduled job by ID. The job is looked up by UUID and dispatched to the message bus for execution with its stored command and parameters. Use this to run a job out of schedule (for example, to backfill data or re-run a failed job).

## Quick start

```bash
make exec cmd="php bin/console app:scheduler:run 0192a3b4-c5d6-7890-abcd-ef1234567890"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | UUID of the scheduled job to trigger |

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Job found and dispatched for execution |
| 1 | No scheduled job exists with the given UUID |

## Tips

- Find the job UUID with `app:scheduler:list`.
- This command only dispatches the job — it does not wait for execution to finish. Check the worker logs to confirm the job ran.
- The job runs with the command and parameters currently stored on it, not with any values you pass on the command line.
