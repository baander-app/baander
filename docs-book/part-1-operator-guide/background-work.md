# Background work

A scan request can be accepted while its files have yet to appear in the catalog.
The web application records and queues work; the worker deployment carries it
through discovery, ingestion and any requested enrichment. A responsive web page
therefore does not establish that background work is progressing.

This chapter explains that path and the worker pair that runs it. Use
[Library management](library-management.md) for scan procedures,
[Monitoring](monitoring.md) for progress and failure signals, and the
[worker deployment reference](commands/README.md#worker-deployment) for the exact
manifest, credentials and host commands.

## The worker deployment pair

A worker deployment is two containers with one deployment namespace and boot
identity. They share a private IPC volume for coordination and native execution.
The web container has no access to that volume or to Docker's socket.

| Unit | Responsibilities |
|------|------------------|
| Control | Renews the database deployment lease; supervises the outbox relay, scheduler poller, autoscaler and media coordinator. |
| Execution | Runs ordinary `async` consumers, one separate `scheduler` consumer and the native execution daemon. Runs probes, encoders, media CPU helpers and scheduled console children. |

The lease gives one admitted boot authority to work in its namespace. Both units
must exchange matching boot and lease-epoch evidence before admitting business
work; execution also needs its native daemon ready. A running container alone
does not establish that these conditions hold. Startup fails if the pair cannot
reach admission within 30 seconds.

The host controller creates and starts the pair from an admitted manifest. Web
Compose startup does not start these workers. Worker runtime uses the credentials
and image selected for that pair, separately from web configuration. See
[Configuration](configuration.md#where-a-change-takes-effect) before changing either.

## From request to result

Several mechanisms carry background work. They have different records and failure
signals, so start by identifying which phase has stopped.

1. **The request records or dispatches work.** Web scans enter the `async` queue.
   A console scan performs discovery in the calling process and records its job
   monitor entry there. Both can produce directory work for later ingestion.
2. **The outbox hands committed events onward.** For directory ingestion, the
   database retains an outbox event describing the accepted directory. Control's
   relay publishes its `FilesDiscovered` message to `async` and records the relay
   acknowledgment. A recorded directory or outbox entry is not an ingested track.
3. **An execution consumer handles the message.** Ordinary consumers receive
   `async` work such as ingestion, metadata, covers and notification delivery.
   Their generated consumer identities distinguish the deployment, boot, role and
   launch generation; do not replace them with a shared custom consumer name.
4. **The feature records its result.** Check its job status and resulting catalog
   or delivery state. Work can dispatch further work, so one completed job need
   not mean the whole user operation has finished.

The queues live in Redis. Job monitor history and Messenger's `failed_messages`
records live in PostgreSQL; outbox records also live in PostgreSQL. Queue state,
job history and catalog state answer different questions. See
[Storage and data](storage-and-data.md#what-must-be-preserved) for preservation and
[Monitoring](monitoring.md) for the available views.

### Retries and failures

Both `async` and `scheduler` use three configured retries with increasing delay.
Recoverable exceptions can request further retries, so this is not a universal
limit on how many times work can run. Exhausted Messenger deliveries go to the
PostgreSQL failure transport, where the failure can be inspected before a manual
retry. Retry and removal are state-changing actions; use the procedure in
[Monitoring](monitoring.md), after correcting the cause.

The outbox has a separate retry record. Its relay uses backoff and marks an event
dead-letter after five failed attempts. A Messenger failure retry does not reset
that outbox record. Inspect relay errors when accepted directories do not reach
ingestion. Do not clear Redis, delete database rows or launch another independent
relay as a general recovery step.

## Scheduled work

Schedules describe when a registered job should run. Control's scheduler records
durable occurrences in PostgreSQL and relays those occurrences to the separate
Redis `scheduler` transport. Execution's scheduler consumer handles them under the
worker deployment's authority. Recovery passes can materialize pending schedule
intents; publication to Redis still does not establish execution success.

The scheduler records its own last result. Read that result as well as the queue
and worker state: a handler can return after recording a failed or skipped job.
For example, the seeded recommendations schedule runs daily but generates nothing
while `recommendations.auto_generate` is off. See
[Scheduled recommendations](configuration.md#scheduled-recommendations).

A scheduled console command runs as a native child in execution. The manifest
must reserve memory for one such child; a reservation of `0` disables scheduled
console execution. If completion becomes uncertain, the scheduler records the
failure and pauses the active schedule to avoid dispatching duplicate work.
Establish deployment containment and inspect the job before resuming it. See
[Scheduler commands](commands/README.md#scheduler) for schedule administration.

## Admission and native work

Worker admission binds the pair to declared CPU, memory, PID and storage limits.
The manifest is an operating recipe, not a measurement of what any library will
need. Its measured concurrency example has workload and host limits documented
in the [worker deployment reference](commands/README.md#worker-deployment).

Execution starts all configured ordinary consumer slots at boot. The autoscaler
changes the **ordinary admission target**: how many slots may acquire permission
to receive work. Lowering the target parks free slots before their next receive.
It does not remove the processes, reclaim their memory or interrupt a handler.
An **occupied permit** remains held through handling, acknowledgment, retry
publication and keepalive. A reduction waits for occupied permits to drain before
admitting more work.

Missing or stale queue/resource measurements prohibit growth and reduce ordinary
admission to at most one. Severe memory or PID pressure closes ordinary admission;
fresh samples are needed for recovery. Missing consumer evidence stays unknown;
it does not count as an idle consumer or an empty queue. Check the decision reason
and evidence age before treating low concurrency as a fault.

Media execution has its own ownership. Control's media coordinator admits at most
three video jobs and one audio rendition. It sends bounded native requests over
the private socket to execution, where FFmpeg, probes and helpers run. Each native
execution attempt carries its deployment and attempt identity. If completion is
uncertain, ownership remains until containment or a confirmed release; cancelling
the caller does not by itself free the native slot. Encoder and file I/O threads
also count toward the execution PID limit.

Worker admission currently accepts no devices. Configure the supported CPU
encoding path described in [Transcoding](transcoding.md), and do not infer worker
hardware support from optional device examples in the web Compose file.

## Observe background work

Use several observations together:

| Observation | What it establishes | Limit |
|-------------|---------------------|-------|
| Web readiness | The web readiness check can reach PostgreSQL and Redis. | It excludes worker readiness. |
| Worker controller `status` | Committed deployment observations and available autoscaling evidence. | Always reports `readiness: "not_checked"`. Missing measurements remain unknown. |
| Worker controller `readiness` | The exact admitted pair, active lease, expected live child identities, Redis connectivity and expected registered consumers were observed. | An observation at that time; it submits no sample job and proves no media or external-provider result. |
| Messenger health | Consumer heartbeat phase and age as seen through Redis. | A heartbeat is not completion evidence for a particular job or the whole pair. Redis failure can make the check unavailable. |
| Job and feature result | The recorded outcome and any resulting catalog or delivery state. | An accepted request, queued message or returned scheduler handler can precede or differ from success. |

Run controller observations on the trusted host from the checkout, with
`APP_HOME` matching the selected image layout as described in
[Getting started](getting-started.md#7-start-web-and-deploy-workers). Keep the exact
manifest and private credentials for the boot being observed. Apply an external
deadline:

```bash
timeout --kill-after=5s 60s php bin/worker-deployment.php status /etc/baander/worker-manifest.json /etc/baander/worker-credentials.json
timeout --kill-after=5s 60s php bin/worker-deployment.php readiness /etc/baander/worker-manifest.json /etc/baander/worker-credentials.json
```

A confirmed readiness result reports `state: "ready"` and `readiness: "ready"`.
Failure or timeout leaves readiness unconfirmed; inspect the cause rather than
starting another pair. Keep stdout separate from stderr when recording controller
JSON. The external timeout command can return without JSON.

For a stalled scan, first distinguish discovery from ingestion. Then inspect the
job result, outbox/relay errors, consumer health and admission evidence for the
phase involved. For a stalled transcode, include media coordinator and native
execution logs. [Monitoring](monitoring.md) connects these signals to diagnostics;
[Troubleshooting](troubleshooting.md) gives symptom-based entry points.

## Stop, replace or recover workers

A web worker reload or web drain does not retire the worker deployment. The
worker pair has its own lease, boot and containment boundary. Follow
[Upgrading](upgrading.md) for an ordered replacement; use the
[worker deployment reference](commands/README.md#worker-deployment) for lifecycle
commands and manifest requirements.

Child exit, peer failure, authority loss, `SIGTERM` or `SIGINT` starts a worker
drain. The supervisors allow 35 seconds before exiting for container teardown if
children remain; external stop handling needs a longer grace period. A
`worker_supervisor_stopped` log records direct-child drain completion, not proof
that every descendant is contained or that the deployment is ready to replace.

Controller actions reserve ownership before submitting Docker work. Preserve
these recovery conditions when a command times out, is interrupted or returns an
unconfirmed result:

- After an uncertain `create`, use `reconcile-create` with the identical manifest
  and credentials. It reconciles never-started units without recreating a claimed
  unit.
- After an uncertain `start`, use `recover`; do not issue another `start`.
- Recovery retires that boot. Both units must have confirmed retirement before
  their joint reservation can be released. An unclaimed unit can be cancelled;
  a claimed unit needs containment evidence.
- Keep the old manifest for recovery. A replacement requires a fresh boot ID;
  changing a recipe or autoscaling profile does not authorize reuse of the old boot.

Timeout does not cancel work already submitted to Docker. Do not release a native
slot, delete deployment records or assume that a missing controller reply means
nothing started. Resolve ownership through the controller before replacement.
