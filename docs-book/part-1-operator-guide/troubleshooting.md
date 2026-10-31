# Troubleshooting

Start with the failed operation and its time: a login, a scan submission, a queued
job or playback. Then check the component that owns that stage. Restarting the web
server can restore a web process, but it does not restart the separate worker
deployment or deliver work waiting in the outbox.

These examples run on the installed Docker host, from the installation checkout,
using the `dc` shell function and private environment files in
[Getting Started](getting-started.md#installation). They target that installation's
Compose services. Worker inspection runs separately on the trusted host with the
exact manifest and credentials file described in
[Background Work](background-work.md#observe-background-work).

## Diagnostic Commands

First collect service state and recent logs, then ask the application to check its
connections and configuration:

```bash
dc ps
dc logs --since=1h --tail=200 app nginx database redis
dc exec -T app php bin/console app:health:check
dc exec -T app php bin/console app:config:validate
```

Read individual rows, including warnings. `app:health:check` can exit successfully
while the consumer or memory row is unhealthy. Its CLI memory row describes the
checking process; Swoole can read `N/A` outside a web worker. It does not check
FFmpeg, outbox delivery or provider access. Configuration validation detects missing
or malformed settings but does not prove that every integration works. See
[Monitoring](monitoring.md#health-checks) for the response and exit-code rules.

| Symptom | Start with | Next evidence |
|---------|------------|---------------|
| Pages return 502 or the web container restarts | [Swoole crashes](#swoole-crashes) | Proxy and application logs; web worker statistics |
| Requests return 503 and jobs stop | [Redis unreachable](#redis-unreachable) | Redis health row, service logs and effective credentials |
| All pages fail with connection errors | [Database connection refused](#database-connection-refused) | PostgreSQL health row and database logs |
| Scan submitted but catalog unchanged | [Media not appearing after scan](#media-not-appearing-after-scan) | Discovery, outbox, consumer and ingestion evidence |
| A mounted view stays stale | [Live views stop updating](#live-views-stop-updating) | Committed producer result, publication backlog, delivery health, authorization and HTTP refresh |
| Stream fails or a quality tier is absent | [Transcoding failures](#transcoding-failures) | Selected media job, FFmpeg and source permissions |
| Login or token renewal fails | [OAuth token issues](#oauth-token-issues) | Configuration, key readability, clock and DPoP error |
| Upload or source access says permission denied | [Permission errors](#permission-errors) | Exact mount, process identity and path permissions |
| Registry registration or recovery fails | [Registry operations](registry.md) | Registry and relay service evidence |

## Log Locations

Use service names through `dc` so the selected checkout and environment files stay
attached to the command:

```bash
# Follow this installation's web logs; Ctrl+C stops following
dc logs -f app nginx

# Bounded logs for a particular service
dc logs --since=1h --tail=100 redis
```

Application and proxy output is available through Docker logs. The supplied
PostgreSQL configuration also collects server logs inside `database` at
`/var/log/postgresql`; inspect the files matching the incident time if Docker logs
only show collector startup. Swoole's configured log is under the application's
`var/log` directory. See [Monitoring: Logs](monitoring.md#logs) for commands and
worker-log boundaries.

## Common Issues

### Live views stop updating

A connected socket does not establish that a change was published or that the
browser read its new contents. Record the affected view, account permissions,
operation time, and the indicator: **Connecting…**, **Updating…**, or **Updates
unavailable**. Then trace the stages in order:

1. Confirm that the producer committed the expected change. For a scan or queued
   job, follow its completion through [Background Work](background-work.md#observe-background-work).
   A submitted job or successful enqueue is not a completed catalog change.
2. Collect web worker diagnostics:

   ```bash
   dc exec -T app php bin/console app:server:stats
   ```

   Inspect `live_updates` for every responding worker. Worker zero owns projection;
   `projector_enabled` need not be true elsewhere. A recent
   `last_successful_projection_check_age_ms` includes empty or busy checks and does
   not prove publication advanced. Compare `projection_error_count`,
   `backlog_observed`, and `last_observed_oldest_pending_age_ms`. That last value
   belongs to the most recent successful observation; it does not age with the
   clock. Null means an empty observed inbox only when `backlog_observed` is true.
   Backlog older than five seconds makes the pipeline degraded.
3. Check delivery separately. `last_successful_reconciliation_age_ms` and
   `max_subscription_read_age_ms` describe successful permission/revision reads;
   null means no successful read was recorded. Compare `active_subscriptions`,
   `read_error_count`, `pending_send_count`, and `slow_peer_close_count` across
   workers. Pending sends exclude a send already in progress. Error and close
   counts accumulate since worker startup, so compare samples and incident times.
4. Inspect the affected browser's network results without copying tokens or DPoP
   proofs. An authorization reset deliberately removes protected cached data and
   obtains `/api/live/recovery` before protected reads resume. A recovery `401` or
   `403` requires checking the account and current library, playlist, or admin
   access. Restoring a socket cannot restore revoked permissions. A temporary
   delivery failure with unchanged permissions instead retains readable data.
5. Once recovery succeeds, confirm that the affected resource endpoint is read
   again and returns the committed result. A failed or slow HTTP refresh can keep
   **Updating…** or **Updates unavailable** visible after delivery recovers. Check
   that endpoint's status and application logs. If its response is current but the
   view is stale, retain a browser reproduction with the view and timestamps for
   a client-refresh investigation.

A `301` followed by a protected collection read's `403` can indicate a client URL
that differs from the registered route, including its trailing slash. DPoP signs
the original request URL; following a redirect does not sign the destination anew.
Retain the path and status sequence without the proof or token for a client fix.
Do not disable proof verification to make the redirected request succeed.

The browser expects readiness within ten seconds and valid health messages within
fifteen seconds. Visible degraded clients also recover over HTTP every thirty
seconds, with bounded retries. These are configured detection and recovery budgets,
not measured end-to-end latency. Server diagnostics describe the web process;
they do not prove rendered browser freshness. See the
[developer guide's field table](../part-2-developer-guide/live-updates.md#inspecting-the-runtime)
for the diagnostic meanings. Verify the original view after correcting the failed
stage, without discarding a user's unsaved edits or restarting playback.

After a worker reload, a socket can remain open while its worker's authentication
and subscription maps have been replaced. The browser replaces that connection
when its ready or health deadline expires and obtains a fresh ticket. An open
socket alone is therefore insufficient recovery evidence: confirm new readiness,
the resource's authorized HTTP response and the rendered result. The same view can
retain readable data during a delivery outage while a later authorization change
requires it to remove protected content.

### Redis unreachable

Sessions, rate-limit checks, DPoP replay protection and background processing can
fail together because they use Redis. Protected requests may return `503` with a
retry delay. This is different from an exhausted rate limit or an invalid token;
see [Redis outage behavior](security.md#sessions-logout-and-a-redis-outage).

1. Inspect `dc ps`, `dc logs --tail=100 redis` and the Redis row in
   `app:health:check`. A stopped service, failed authentication and exhausted Redis
   memory require different corrections.
2. Privately compare the effective `REDIS_URL` used by the application and worker
   credentials with the Redis service password. In the supplied stack, Compose
   takes its password from `REDIS_PASSWORD`. Check the host and port too: the
   container-side service address differs from a host-controller address. Do not
   paste connection URLs or environment-file contents into an incident report.
3. If the service is stopped, establish why before starting that service with
   `dc up -d redis`. For a credential mismatch, use the coordinated
   [Redis password rotation procedure](security.md#rotating-redis-password).
   Recreating a service to apply environment changes is different from restarting
   an existing process; keep the application and worker credentials consistent.
4. Run `app:health:check` again, observe worker readiness and follow a pending job
   to progress. Restored connectivity alone does not show that delayed work has
   completed.

If the service is running but unreachable, inspect the selected Compose network
and service attachments. Correct the installation's network configuration before
recreating an affected service. Keep Redis data and queued messages intact; use
[Backup and Recovery](backup-and-recovery.md) if state recovery is required.

### Transcoding failures

A failed stream or missing quality tier can come from an unreadable source, an
unsupported codec, FFmpeg, worker execution or resource exhaustion.

1. Identify the media item and failed operation in the admin interface. Inspect
   the associated job, when present, with the job-monitor commands in
   [Monitoring](monitoring.md#job-monitoring). A health check is not a list of failed
   transcodes.
2. Identify the execution container and immutable image from the exact worker
   deployment's controller status. Inspect that unit's logs for probe or encoder
   errors. FFmpeg runs in this worker image; checking it in `app` only checks web.
   If the worker image is missing the binary, correct and rebuild the
   [worker image](commands/README.md#worker-deployment), then follow the confirmed
   retirement and fresh-boot procedure in [Upgrading](upgrading.md).
   Repeatedly retrying the job will not add the binary.
3. Inspect the source path and permissions in the container executing the work.
   The same source mount must be available to the admitted worker when it handles
   queued work. See [Storage and Data](storage-and-data.md).
4. Read app and worker logs around the failure. Check memory, disk space and codec
   errors before changing resource settings. The admitted worker recipe does not
   support GPU passthrough; see [Hardware acceleration](transcoding.md#hardware-acceleration).
   Use [server statistics](monitoring.md#server-stats) for the web process; they do
   not describe worker container limits.
5. Once the cause is corrected, choose a single retry path from
   [failed-message handling](monitoring.md#failed-messages). Verify playback of the
   affected item and the expected tier, rather than relying on a healthy probe.

### Permission errors

A path can be readable on the host and inaccessible to the user running the
application or worker. Check the exact failing path and its parent directories.
Replace `/media/music` below with the configured container-side library path:

```bash
dc exec -T app id
dc exec -T app ls -ld /media /media/music
```

Compare the effective process UID and GID with the mounted host path's owner, group,
mode and ACL. Check whether the mount is read-only. `HOST_UID` and `HOST_GID` are
image build inputs in the supplied workflow; changing a host shell variable does
not change an already built image's user. The worker has a separate mount
specification and must be checked separately.

For source media, grant the required read and directory traversal access. Writable
application data needs access at its own destination. Change only the intended
path's permissions after confirming ownership; a recursive ownership change over
all media or configuration can damage shared access. See
[Storage and Data](storage-and-data.md) for source and generated-data boundaries and
[File permissions](security.md#file-permissions) for secret-file protection.
Verify the same operation with the actual application or worker identity afterward.

### Swoole crashes

A 502 response can mean that Nginx cannot reach the web server; repeated container
restarts can mean the process exits or is killed.

```bash
dc ps
dc logs --tail=200 nginx app
```

Inspect the installed app container without assuming its Docker name:

```bash
docker inspect --format '{{.RestartCount}} {{.State.Status}} {{.State.OOMKilled}}' "$(dc ps -q app)"
```

Read the timestamps and error preceding an exit. If the process still responds,
collect `dc exec -T app php bin/console app:server:stats` to see web worker memory,
missing responses and connection pools. An OOM kill or increasing memory use
requires checking the container limit and workload before adjusting worker counts
or PHP's memory limit. Use the effective Swoole settings in
`config/packages/swoole.yaml`; do not assume a shell variable changes them.

After correcting the cause, use the [web stop and restart procedure](upgrading.md#stopping-and-restarting-the-web-server),
then verify `/live`, `/ready` and the failed user operation. A planned web restart
leaves the admitted background worker deployment running.

### Database connection refused

A refused connection means the client could not establish a database connection;
authentication, database-name and schema errors occur later and need their own fix.

1. Check `dc ps` and `dc logs --tail=100 database`. If the logging collector is
   active, also inspect `/var/log/postgresql` inside `database` for the incident.
2. Privately check the effective `DATABASE_URL` and selected environment files.
   Inside the supplied Compose network the hostname is `database`, not
   `localhost`. The host controller uses its own reachable address. Compare the
   database name and credentials without printing the URL.
3. If PostgreSQL is stopped, identify the cause and preserve its volume before
   starting it with `dc up -d database`. Storage corruption or missing data calls
   for [Backup and Recovery](backup-and-recovery.md), not a new empty volume.
4. Run `app:health:check` from the app container. Its PostgreSQL row executes
   `SELECT 1`. Then verify the failed operation and worker readiness. A successful
   connection does not establish that the schema is compatible with the running
   code; check [Upgrading](upgrading.md) if failures began after a release change.

### OAuth token issues

First distinguish a login rejection, an expired token, a DPoP proof rejection and
a dependency outage. Preserve the HTTP status and error from the client, and the
server timestamp. A Redis outage can produce a retryable `503` during an otherwise
valid request.

1. Run `app:config:validate` and inspect its key-file and secret-strength results.
   Privately confirm that the intended environment and OAuth keys are loaded by
   every participating process. Do not print `APP_SECRET`, encryption keys or
   private key contents.
2. Confirm that clients use the expected HTTPS origin and correct time. A bound
   access token needs the matching DPoP key and a fresh proof for the request; a
   token-only curl command does not reproduce an authenticated application request.
3. If the problem began after a configuration change, compare the effective
   settings and key files with the preserved deployment record. An application
   secret, OAuth signing keys and the OAuth encryption key serve different roles.
4. Follow [Security](security.md) for the particular secret involved. OAuth key
   rotation is an offline coordinated procedure, not a routine diagnostic step.
   Verify login, token refresh and a protected request with a supported client
   after the repair. If exposure is suspected, use
   [Breach Recovery](security.md#breach-recovery).

### Media not appearing after scan

Discovery and ingestion are separate. A scan can find files and queue ingestion
without immediately creating catalog entries. Current catalog ingestion supports
music and movie libraries; selecting another media type does not establish an
implemented ingestion path. See [Library Management](library-management.md).

1. Confirm the library's configured path and media type. Inspect that path inside
   `app`, and confirm the admitted worker has the matching mount and read access.
   A host directory alone does not make files visible inside either container.
2. Inspect the scan result and logs. Distinguish a scan that has only been
   submitted from one that has finished discovering files. If paths or filters
   explain zero discovered files, correct those before submitting another scan.
3. For files queued for ingestion, follow the worker observations in
   [Background Work](background-work.md#observe-background-work). Check the outbox
   relay as well as the consumer. Read the `messenger` component in a fresh
   `/health` response: the top-level status can be healthy while workers are
   stopped, and saved readiness flags can outlive them. `/ready` checks only
   PostgreSQL and Redis; a fresh consumer heartbeat does not prove that the outbox
   relay has delivered a message.
4. Inspect current and failed jobs:

   ```bash
   dc exec -T app php bin/console app:monitor:status
   dc exec -T app php bin/console app:monitor:jobs --status=failed --limit=20
   dc exec -T app php bin/console app:monitor:transport
   dc exec -T app php bin/console app:failed-message:list
   ```

   Match the operation and timestamps. Redis stream length and consumer
   registration are not proof of live consumption, and an empty Redis stream does
   not rule out undelivered outbox work. Inspect worker logs and job details before
   retrying. If termination of a worker is uncertain, follow the explicit recovery
   rules in Background Work before starting any replacement.
5. Once ingestion completes, check the catalog item. Covers, artist information
   and other enrichment may follow through separate provider work. Diagnose missing
   enrichment after confirming the item exists; provider-key configuration and
   outbound access are separate from local file discovery.

If a rescan is needed after correcting the source or configuration, use the
[scan procedure](library-management.md#scanning), then observe it through
ingestion. Repeated scan submissions are not a substitute for restoring a stopped
relay or consumer.

## Getting Help

Collect the failed operation, timestamps, affected component, service state and
relevant job IDs. Include the diagnostic rows and a bounded log excerpt after
redacting credentials, tokens, private paths and personal data. Record whether the
failure affects web requests, background work or both, and what changed before it
started.

Report the issue through the tracker for the repository used by your installation.
Include reproduction steps and expected behavior. Use your project's private
reporting channel for exposed credentials or a suspected security vulnerability.

## See Also

- [Monitoring](monitoring.md) — component signals, endpoints and job handling
- [Background Work](background-work.md) — worker ownership, observation and recovery
- [Storage and Data](storage-and-data.md) — mounts and data ownership
- [Backup and Recovery](backup-and-recovery.md) — preserve and restore state
- [Security](security.md) — secret rotation and incident recovery
- [app:health:check](commands/app-health-check.md) — health-check result rules
- [app:config:validate](commands/app-config-validate.md) — configuration checks
