# app:monitor:transport

Show the health of the Messenger transports: how many entries wait in the async Redis stream, how many messages the failure transport holds, and whether the consumer is registered on the stream. The command shows what `GET /api/monitor/transport/status` returns in the admin API and what the **Transport Health** card of the admin Job Monitor displays. Both read the figures through the same service.

## Quick start

```bash
make exec cmd="php bin/console app:monitor:transport"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the data as the admin API returns it, in JSON |

## Details

The command prints four figures:

| Field | Content |
|-------|---------|
| Async queue | Entries in the `messages` Redis stream that the `async` transport uses |
| Failed queue | Messages in the failure transport, including those waiting out a retry delay |
| Consumer | The consumer name this container is configured with, from `MESSENGER_CONSUMER_NAME` |
| Consumer running | `yes` when the `baander` consumer group on the stream lists that consumer, otherwise `no` |

The consumer check is best effort. A consumer stays listed in the group after its worker stops, and a stream or group that does not exist yet reads as `no`.

With `--json`, stdout holds only the `data` object of the API response: `asyncQueueDepth`, `failedQueueDepth`, `consumerName` and `consumerRunning`.

When Redis cannot be reached the command prints `Redis unavailable:` followed by the reason. When the failure transport's table cannot be read it prints `Failure transport unavailable:` followed by the reason. The API answers both cases with status 503.

To list or remove the failed messages, use [app:failed-message:list](app-failed-message-list.md) and [app:failed-message:flush](app-failed-message-flush.md).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Figures printed |
| 1 | Redis or the failure transport could not be read; the message says why |
