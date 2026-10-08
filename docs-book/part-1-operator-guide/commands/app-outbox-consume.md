# app:outbox:consume

Relay the durable outbox. Each pass relays committed domain events to the notification and admin-alert listeners, then hands committed notification delivery intents to the Redis `async` transport. In a worker container, the [app:worker](app-worker.md) supervisor runs this command as its relay process; run it by hand to process a backlog or to check the relay.

## Quick start

Process one batch of each queue and exit:

```bash
make exec cmd="php bin/console app:outbox:consume --once"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--once` | off | Process one bounded batch of events and one of delivery intents, then exit |
| `--time-limit` | `3600` | Maximum run time in seconds, from 1 to 86400 |

## Details

Without `--once`, the command repeats a pass every second until the time limit passes, it receives `SIGTERM` or `SIGINT`, or its memory use reaches 256 MiB. A signal lets the current pass finish before the command exits.

A failure in one queue does not stop the other: the error is logged and the failed records stay eligible for a later pass. A lost acknowledgement can repeat a handoff, so an email, push or webhook delivery is not guaranteed to happen exactly once.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The relay stopped normally, or the `--once` batch succeeded |
| 1 | With `--once`, a batch failed; the error is in the log |
| 2 | `--time-limit` is not a whole number from 1 to 86400 |
