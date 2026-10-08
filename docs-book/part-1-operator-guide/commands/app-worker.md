# app:worker

Run the worker supervisor of a worker container. It starts and watches three child processes: a Messenger consumer for the `async` and `scheduler` transports, the outbox relay ([app:outbox:consume](app-outbox-consume.md)) and the scheduler poller. It is the entrypoint of the `worker` image target, and `bin/worker-deployment.php` passes its options from the deployment manifest; see [Worker deployment](README.md#worker-deployment). It refuses to start unless it is its container's PID 1, so it cannot run in the app container.

## Quick start

The deployment tool runs the command as the container's PID 1, in this form:

```bash
php bin/console app:worker --no-interaction --deployment=worker.baander.app --boot-id=0123456789abcdef0123456789abcdef \
  --memory-mib=1280 --management-mib=128 --consumer-mib=320 --relay-mib=320 --scheduler-mib=320 \
  --scheduled-console-mib=192 --lock-dir=/tmp/baander-worker-locks
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--deployment` | `BAANDER_WORKER_NAMESPACE` | Deployment namespace |
| `--boot-id` | `BAANDER_WORKER_BOOT_ID` | Fresh 32-character hexadecimal boot identity |
| `--memory-mib` | — | Total admission ceiling in MiB (required) |
| `--management-mib` | — | Reservation for the supervisor and its helpers in MiB, at least 128 (required) |
| `--consumer-mib` | — | Reservation for the Redis consumer in MiB, at least 320 (required) |
| `--relay-mib` | — | Reservation for the outbox relay in MiB, at least 320 (required) |
| `--scheduler-mib` | — | Reservation for the scheduler in MiB, at least 320 (required) |
| `--scheduled-console-mib` | `0` | Reservation for one synchronous scheduled console command in MiB; `0` disables scheduled console commands, otherwise at least 192 |
| `--lock-dir` | `/tmp/baander-worker-locks` | Private local lock directory |

## Details

When the environment sets `BAANDER_WORKER_NAMESPACE` or `BAANDER_WORKER_BOOT_ID`, the matching option must be absent or equal to it. Memory values are whole MiB up to 1048576. The reservations are admission limits, not measured usage or limits the operating system enforces.

The deployment drains when a child exits, when the deployment loses its lease, or on `SIGTERM` or `SIGINT`: the supervisor stops every child and exits. If children are still running 35 seconds after the drain starts, the supervisor exits with code 1 and leaves them to the container's teardown. After a drain the supervisor prints one JSON line with the event `worker_supervisor_stopped`, the number of launch attempts and the exit code.

The supervisor does not restart itself. A failed deployment must be reconciled with `bin/worker-deployment.php` before it is replaced.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The deployment drained after a stop signal |
| 1 | A child or the lease failed, the drain timed out, or the supervisor could not start; error output omits diagnostics that could contain secrets |
| 2 | An option is missing or invalid, or it differs from the container's deployment or boot identity |
