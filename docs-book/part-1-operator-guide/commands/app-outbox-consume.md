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

Live domain events are captured in the outbox. Replay uses a private dispatcher
for notification and admin-alert listeners; session and transcode listeners stay
on the live dispatcher. Each event's receipt, notifications and channel delivery
intents commit in one transaction. Replaying the same outbox ID skips an already
committed receipt.

Committed delivery intents use the versioned JSON message contract and are handed
to Redis `async`. A lost acknowledgement can repeat a handoff, so an email, push
or webhook delivery is not guaranteed to happen exactly once.

A failure in one queue does not stop the other: the error is logged. Events and
delivery intents use leased claims and retry backoff, reaching dead-letter state
after five failed attempts. Records awaiting a retry stay eligible for a later
pass once their delay has elapsed.

The [health endpoints](../monitoring.md#what-is-checked) check the Redis consumer;
they do not yet check this relay. The worker deployment owns its lifecycle; see
[Worker deployment](README.md#worker-deployment) for drain and recovery procedures.

The producer runtime drill verifies that operator user creation, user registration
and email verification commit domain writes and event capture in one transaction.
Other event producers still need their transaction boundaries reviewed. See
[CQRS and Messaging](../../part-2-developer-guide/cqrs-and-messaging.md#durable-event-replay)
and [Testing](../../part-2-developer-guide/testing.md) for the mechanism and runtime
coverage.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The relay stopped normally, or the `--once` batch succeeded |
| 1 | With `--once`, a batch failed; the error is in the log |
| 2 | `--time-limit` is not a whole number from 1 to 86400 |
