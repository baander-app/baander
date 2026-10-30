# app:worker

Run one unit of the paired worker deployment as its container's PID 1. Control
supervises the outbox relay, scheduler poller, independent autoscaler and media
coordination. Execution runs resident ordinary `async` consumers, one separate
`scheduler` consumer and the native execution daemon.
Native probes, encoders, CPU helpers and scheduled console children run in
execution. The host `bin/worker-deployment.php` supplies the options from a version
5 manifest; see [Worker deployment](README.md#worker-deployment).

## Quick start

The host deployment controller constructs this command and runs it as PID 1 in
each admitted worker container. Do not launch it with `dc exec` or as an
additional web-container process. Use the host
[manifest reference](README.md#worker-deployment) to provision the pair, and
[Background work](../background-work.md#stop-replace-or-recover-workers) for
start, stop, replacement and uncertain outcomes.

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--deployment` | `BAANDER_WORKER_NAMESPACE` | Deployment namespace |
| `--boot-id` | `BAANDER_WORKER_BOOT_ID` | Fresh 32-character hexadecimal boot identity |
| `--unit` | `BAANDER_WORKER_UNIT` | Required unit identity: `control` or `execution` |
| `--memory-mib` | — | Required unit ceiling: at least 2944 MiB for control or `4160 + 320 * (maximumConcurrency - 1)` MiB plus the scheduled console reservation for execution |
| `--pids-limit` | — | Required unit PID capacity: at least 129 for control or `256 + maximumConcurrency - 1` for execution; at most 4096 |
| `--scheduled-console-mib` | `0` | Execution reservation for one synchronous scheduled console child; `0` disables it, otherwise at least 320 MiB; control requires `0` |
| `--autoscaling-profile` | — | Required complete JSON profile from the version 5 manifest; all fields bind both unit recipes |
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

Control owns the deployment lease; execution observes it. Both units require
matching boot/epoch peer evidence before business admission.
[Background work](../background-work.md#stop-replace-or-recover-workers) defines
peer failure and the 35-second drain deadline; the
[worker pair](../background-work.md#the-worker-deployment-pair) has a 30-second
startup admission deadline. A `worker_supervisor_stopped` log entry is neither
a readiness result nor a containment receipt.

The media coordinator admits at most three video jobs and one audio rendition.
Each job uses a fresh kernel in control and sends bounded native requests over
the private Unix socket to execution. Hardware probing, FFmpeg, video CPU helpers
and scheduled console children belong to execution; no devices are admitted.
Native requests carry deployment, boot, epoch, client generation and attempt
identity. Uncertain completion retains ownership until containment or a confirmed
release; caller cancellation alone does not free a native slot. Encoder and file
I/O threads count against execution's PID ceiling.

The supervisor does not restart itself. Reconcile a failed deployment through the
host controller before replacing it; see the lifecycle procedure linked above.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The deployment drained after a stop signal |
| 1 | A child or the lease failed, the drain timed out, or the supervisor could not start; error output omits diagnostics that could contain secrets |
| 2 | An option is missing or invalid, it differs from the container's deployment, boot or unit identity, or worker transport configuration is invalid |
