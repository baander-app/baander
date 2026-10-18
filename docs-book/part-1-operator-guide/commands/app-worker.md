# app:worker

Run one unit of the paired worker deployment as its container's PID 1. Control
supervises the outbox relay, scheduler poller, independent autoscaler and media
coordination. Execution runs resident ordinary `async` consumers, one separate
`scheduler` consumer and the native execution daemon.
Native probes, encoders, CPU helpers and scheduled console children run in
execution. The host `bin/worker-deployment.php` supplies the options from a version
4 manifest; see [Worker deployment](README.md#worker-deployment).

## Quick start

The deployment tool runs the command as the container's PID 1. These examples use
the measured minimum-one/maximum-two admission envelope and the complete `autoscaling` object from the
admitted manifest:

```bash
profile=$(php -r '$m = json_decode(file_get_contents($argv[1]), true, 8, JSON_THROW_ON_ERROR); echo json_encode($m["autoscaling"], JSON_THROW_ON_ERROR);' /etc/baander/worker-manifest.json)
php bin/console app:worker --no-interaction --deployment=worker.baander.app --boot-id=0123456789abcdef0123456789abcdef \
  --unit=control --memory-mib=2944 --pids-limit=129 --scheduled-console-mib=0 \
  --lock-dir=/tmp/baander-worker-locks --ipc-dir=/run/baander-worker --autoscaling-profile="$profile"

php bin/console app:worker --no-interaction --deployment=worker.baander.app --boot-id=0123456789abcdef0123456789abcdef \
  --unit=execution --memory-mib=4800 --pids-limit=257 --scheduled-console-mib=320 \
  --lock-dir=/tmp/baander-worker-locks --ipc-dir=/run/baander-worker --autoscaling-profile="$profile"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--deployment` | `BAANDER_WORKER_NAMESPACE` | Deployment namespace |
| `--boot-id` | `BAANDER_WORKER_BOOT_ID` | Fresh 32-character hexadecimal boot identity |
| `--unit` | `BAANDER_WORKER_UNIT` | Required unit identity: `control` or `execution` |
| `--memory-mib` | — | Required unit ceiling: at least 2944 MiB for control or `4160 + 320 * (maximumConcurrency - 1)` MiB plus the scheduled console reservation for execution |
| `--pids-limit` | — | Required unit PID capacity: at least 129 for control or `256 + maximumConcurrency - 1` for execution; at most 4096 |
| `--scheduled-console-mib` | `0` | Execution reservation for one synchronous scheduled console child; `0` disables it, otherwise at least 320 MiB; control requires `0` |
| `--autoscaling-profile` | — | Required complete JSON profile from the version 4 manifest; all fields bind both unit recipes |
| `--lock-dir` | `/tmp/baander-worker-locks` | Private unit lock directory |
| `--ipc-dir` | `/run/baander-worker` | Private IPC directory shared by the pair |

## Details

When the environment sets `BAANDER_WORKER_NAMESPACE`, `BAANDER_WORKER_BOOT_ID` or `BAANDER_WORKER_UNIT`, the matching option must be absent or equal to it. Memory values are whole MiB up to 1048576. The host manifest binds these admission values to separate Docker memory, CPU and PID ceilings and disjoint CPU sets. They do not certify measured usage or capacity.

Execution starts `consumer-0` through `consumer-(maximumConcurrency - 1)` for
`async`, plus one separate `scheduler` consumer. Every ordinary slot remains
resident and fully reserved, including when parked. Parking gates the next receive
without reclaiming RSS or interrupting handling, acknowledgment, retry publication
or keepalive. A shrink waits for occupied permits to drain below the target before
admitting more work. Control's independent private autoscaler collects queue and
execution cgroup evidence and updates admission through boot/epoch-fenced IPC;
it does not consume ordinary work. It reserves 320 MiB within control's ceiling.

The [manifest reference](README.md#worker-deployment) defines profile bounds and
the measured maximum-two example and its workload limits. Missing measurements prevent growth and reduce
ordinary admission to at most one. Severe memory/PID pressure closes admission;
recovery needs fresh samples. Status exposes slot counts, decision reason and
freshness without treating missing consumers as idle.

Container compilation checks default routes,
handlers registered for the transport, payload codecs, JSON serialization and
bounded retry policies. Producer dispatch checks the actual selected senders,
including explicit transport stamps. Catalog resource classes describe work; the
unit budgets above remain the admission policy. PHP kernel processes use a
256 MiB heap ceiling; unit memory also reserves native and management headroom.

The supervisor checks `REDIS_URL` and `MESSENGER_TRANSPORT_DSN` before launching
children. Accepted forms preserve the configured streams and `baander` group;
consumer overrides are rejected. Each admitted consumer receives an identity
derived from the deployment, boot, worker role and launch generation, replacing
an inherited `MESSENGER_CONSUMER_NAME`. See [Messenger configuration](../configuration.md#messenger)
for URL forms. Invalid configuration exits without child launch, and errors omit
credential-bearing values. The host deployment controller applies the same URL
checks before container creation.

Control acquires and renews the deployment lease; execution only observes its
committed authority. Before business admission, both units require matching peer
boot/epoch evidence and execution requires a ready native daemon. Startup without
admission fails after 30 seconds. Child exit, peer failure, authority loss,
`SIGTERM` or `SIGINT` starts a drain. If children remain after 35 seconds, PID 1
exits with code 1 for container teardown. A completed direct-child drain emits
`worker_supervisor_stopped` with launch attempts and exit code; this is no readiness
or containment receipt.

The media coordinator admits at most three video jobs and one audio rendition.
Each job uses a fresh kernel in control and sends bounded native requests over
the private Unix socket to execution. Hardware probing, FFmpeg, video CPU helpers
and scheduled console children belong to execution; no devices are admitted.
Native requests carry deployment, boot, epoch, client generation and attempt
identity. Uncertain completion retains ownership until containment or a confirmed
release; caller cancellation alone does not free a native slot. Encoder and file
I/O threads count against execution's PID ceiling.

The supervisor does not restart itself. A failed deployment must be reconciled with `bin/worker-deployment.php` before it is replaced.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The deployment drained after a stop signal |
| 1 | A child or the lease failed, the drain timed out, or the supervisor could not start; error output omits diagnostics that could contain secrets |
| 2 | An option is missing or invalid, it differs from the container's deployment, boot or unit identity, or worker transport configuration is invalid |
