# Upgrading

An upgrade changes three things independently: the web image and checkout, the
database schema, and the admitted worker pair. Plan an outage for writers when
the schema or message contract changes. A healthy web response alone does not
show that the new workers can process the old pending work.

## Standard Update

Before pulling this update, retire any singleton worker deployment with the old
checkout, controller, manifest and credentials. Stop controller activity before
applying migrations. The paired-deployment migration refuses active leases,
unresolved creations, incomplete retirements or inconsistent singleton history;
lease expiry is insufficient. Resolve these through the old controller while its
schema is still installed. Preserve the database, pending work and media; no reset
is required or authorized by this upgrade.

Version 5 manifests require `sourceMountPolicy` and the complete ordinary
autoscaling profile. The new controller refuses earlier manifest versions, including
recovery actions. Keep the old checkout, controller, manifest and credentials to
retire an existing pair before switching code. Confirm both units' retirement and
the joint reservation release, then create a version 5 manifest with a fresh boot
ID, the new image and `sourceMountPolicy: production` for the named-volume recipe.
Lease expiry or a stopped container is insufficient. Preserve the database, pending
work and media. This manifest change requires no data reset and provides no
compatibility path.

Use the `dc` function and private environment file defined in
[Getting Started](getting-started.md#1-prepare-configuration-and-the-browser-address).
The development Make lifecycle commands target isolated development instances;
they do not operate this source installation.

Before changing code, record the current revision, image IDs, private configuration,
worker manifest and credentials. Stop incoming writes and retire the old worker
pair while its controller and schema still agree. Follow
[Backup and recovery](backup-and-recovery.md) to preserve a consistent recovery
set; a code checkout is not a database backup. Keep the old controller available
until both units are confirmed retired. Stop if retirement is unconfirmed.

On the installation host, select the reviewed target revision. Preserve local
Compose and private configuration changes when updating the checkout. Then build
the application with the same builder used for installation:

```bash
php scripts/build-application.php --target dev --tag baander:local --compose-env .env.compose.local
dc build nginx database
```

The generated `.env.compose.local` selects the immutable application image and its
`APP_HOME`. `dc build` alone cannot build the application. The `dev` target here
belongs to the source-installation recipe; it does not turn this checkout into a
managed preview. Preserve the installation's volumes and network.

Keep the web service stopped while applying a schema change. With PostgreSQL and
Redis available, update dependencies in the mounted checkout and migrate through
one-off application containers:

```bash
dc stop app
dc run --rm --no-deps app composer install --no-interaction --no-progress --no-scripts
dc run --rm --no-deps app composer dump-autoload --optimize --no-interaction
dc run --rm --no-deps app php bin/console doctrine:migrations:migrate --no-interaction
dc run --rm --no-deps app sh -c 'cd "$APP_HOME/ui/web" && corepack yarn install --immutable'
dc run --rm --no-deps app sh -c 'cd "$APP_HOME/ui/web" && corepack yarn build'
```

The source recipe mounts the checkout over the image, so its frontend assets also
need rebuilding before web starts. Stop on a failed install, migration or frontend
build and inspect its error. Do not
start old code against a partially changed schema. Replacing or upgrading the
database service itself requires a database-version-specific procedure; the
commands here do not perform a PostgreSQL major-version upgrade.

Start web only after migrations succeed:

```bash
dc up -d --no-deps app
dc exec nginx nginx -s reload
dc exec app php bin/console app:config:validate
dc exec app php bin/console app:health:check
```

These commands update the web/dependency stack. The separately admitted worker
uses an immutable image and boot identity. Before changing its image or storage
recipe, use the host [deployment controller](commands/README.md#worker-deployment)
to recover and retire the old deployment, then create/start a new boot with the
updated image and manifest. Never enable automatic worker restart or place its
children under another supervisor. Prepare shared media volumes before starting
either role, and preserve pending work and existing media.

Start and observe the fresh worker pair before allowing new work. Check its
explicit readiness observation, inspect transport state, sign in, and test the
media path needed by your users. [Monitoring](monitoring.md) distinguishes these
checks and their limits. Reopen access only after the relevant checks pass.

Check the commit log for notable changes between your current version and the one you are upgrading to:

```bash
git log --oneline <current-tag>..HEAD
```

## Queued Messages and Retry Data

Before upgrading an instance with older queued messages:

1. Stop producers and preserve queued and retrying Redis work while retiring the
   old worker deployment through the [deployment controller](commands/README.md#worker-deployment).
2. Preserve Redis streams and the PostgreSQL database, which contains failed
   messages, outbox rows and job history. See the
   [recovery inventory](backup-and-recovery.md#choose-the-recovery-set). Removing
   failed messages discards work; it is not a backup operation.
3. Upgrade producers and workers together, following the worker retirement and
   fresh-boot procedure above.

Queued commands and stored job retry data use the `baander.message` JSON format,
version 1. PHP-serialized envelopes and legacy `__class` job payloads are rejected;
archived legacy jobs cannot be retried through the new codec. Do not remove
pending messages to make an upgrade pass. See [CQRS and Messaging](../part-2-developer-guide/cqrs-and-messaging.md#message-contract-and-retries)
for the contract and retry behavior.

## Database Migrations

`dc exec app php bin/console doctrine:migrations:migrate --no-interaction` runs
pending migrations on the configured main database. Run it after pulling new code:
migrations may contain data transformations or index additions. Test databases
belong to the separate disposable test coordinator.

Fresh databases start with the transactional migration `Version20261017000000`,
then apply forward migrations through `Version20261021120000`. The initial
migration creates the schema, including microsecond `timestamptz` columns, and
cannot be downgraded. Forward migrations add playlist persistence versions and
video/audio execution authority. `Version20261017160000` preserves durable
session/request control receipts; `Version20261017170000` scopes video jobs to
playback owners without rewriting the initial migration or existing histories.
`Version20261018130000` imports confirmed singleton retirement history and adds
the control/execution journals and joint retirement barrier. It is irreversible.
`Version20261021120000` retains the last observed availability of indexed source
files and is also irreversible.
New deployments require a version 5 manifest with separate budgets, disjoint CPU
sets, a private IPC volume and a complete autoscaling profile; earlier manifest
versions are refused. Every profile field is fingerprinted in both recipes, so
changing thresholds or concurrency also requires retirement and a fresh boot.

Ownership is derived from the recorded runtime session or an unambiguous session
owner. Ambiguous or unattached historical jobs retain a null owner. Existing
accepted, ready and complete execution generations are fenced and queued for
re-encoding into job-specific output paths; old output does not become current
through the migration. Preserve the shared source and derived volumes during this
update.

A database already initialized with `Version20261017000000` applies the forward
migrations through the normal update commands; no database reset is required.
Migrations run in timestamp order, and a repeat run leaves no pending work.

While the initial migration is pending, Doctrine refuses a target containing user
tables, views, sequences, types or routines. A table counts even without rows.
System objects, extension-owned objects and an empty Doctrine migration metadata
table are excluded. The refusal identifies the database without printing
credentials and occurs before application DDL. Non-interactive execution and
Doctrine's ordinary migration confirmation do not bypass this check.

Executed migrations absent from `migrations/`, including the former migration
chain, also cause refusal. Do not mark the new initial migration as executed or
edit migration metadata to bypass either guard.

### Manual reset of an existing database

The consolidated migration provides no automatic upgrade from the former chain.
Resetting an existing database requires its owner's explicit approval for that
specific target and a separate manual operation outside Doctrine migrations.

1. **Identify the target and obtain approval.** Confirm the database and server
   without exposing connection credentials. Agree which data must be exported or
   backed up before the owner approves deleting that database.
2. **Stop its writers.** Record which application services are running and stop
   the processes that can write to the approved target.
3. **Recreate only the approved database manually.** Preserve its database creation
   settings. Leave other databases, PostgreSQL volumes, Redis, registry storage
   and media files untouched. Do not use a reset helper that targets more than the approved database.
4. **Initialize and verify.** Run Doctrine migrations against that empty target,
   then run them again and verify there is no pending work. For the configured
   main database alone, use `dc exec app php bin/console doctrine:migrations:migrate --no-interaction` on both runs.
5. **Restore service state.** Restart the services recorded in step 2 and complete
   the [initial setup](getting-started.md#5-initialize-the-main-database-and-authentication) required by the empty
   application database.

## When to Rebuild Docker Images

Rebuild the affected image when any of the following have changed:

- The `Dockerfile` or `.dockerignore`
- PHP extensions (added or removed in the Dockerfile)
- System packages in the Dockerfile (e.g., `apt-get install`)
- The base image tag

Use `scripts/build-application.php` for the application image, then the worker
recipe for its immutable image. Use `dc build nginx database` for the Compose
services with build definitions. A successful build does not replace running
services or migrate their data. Build inputs and cache settings are described in
the [development environment guide](../part-2-developer-guide/development-environment.md).

## Docker Compose Changes

When `docker-compose.yml` changes (new services, new volume mounts, network changes, environment variable additions), a simple `dc restart` is not enough -- Docker Compose needs to recreate the affected containers from scratch.

Recreate only the affected services after checking their volumes, ports and
credentials with the installation's Compose context. Use `dc up -d --no-deps app`
for an application-only change. A dependency recreation can interrupt work or
lose unpersisted Redis state; preserve that state before the change. Never use
`down -v` as an upgrade step.

## Stopping and Restarting the Web Server

An update that replaces the app container stops the web server in it, and so do `dc stop`, `dc restart`, `docker stop` and `docker compose restart app`. Docker sends `SIGTERM` to `app:serve`, and the server drains: it finishes what it can, tells clients to come back, and exits on its own. `SIGINT`, `swoole:server:stop` and `DELETE /api/server` on the internal API server start the same drain. A second stop request during a drain changes nothing, and a worker reload (`PATCH /api/server`) never starts one.

### What the drain does

The drain ends within the drain deadline `WEB_DRAIN_SECONDS` (D, 30 seconds by default; see [Configuration](configuration.md#server)):

1. The server accepts no new connections. Connections already open keep working. Requests that still arrive on them are answered with `Connection: close`, `/ready` answers `503`, and `/live` answers `200` with `"draining": true`.
2. Every WebSocket receives a close frame with code `1012` ("service restart"). Session-sync clients reconnect once the server is back, with a current access token.
3. Requests in progress finish with complete responses. Background commands use Redis `async`; web has no task workers, CPU pool or media executor. The worker deployment keeps its consumers and encoders while web drains.
4. When the drain window ends (13 seconds at the default), the server closes every response it is still sending, such as an audio stream, without finishing it. The client sees a transfer error, not the end of the track. The server then resets every connection still open.
5. Swoole stops the HTTP workers. Video and audio execution remain in the independent worker deployment; reconnecting clients read the persisted media state and current generation.
6. The server exits with status `0`. A drain that had nothing to wait for takes under a second.

If the server's manager process is still running at the deadline, the drain watchdog, [`app:server:drain:watch`](commands/app-server-drain-watch.md), kills it with `SIGKILL`, and the server exits with status `1`. The server logs a line when the drain starts, with the deadline, the window and Swoole's `max_wait_time`, and another when the window closes. A planned stop sends no [health alert](monitoring.md#what-is-checked).

### Stop timeout

Give the container a stop timeout of at least D + 5 seconds, 35 seconds at the default, before the runtime sends `SIGKILL`:

- Docker Compose: `stop_grace_period: 35s` on the app service. The development `docker-compose.yml` sets this.
- `docker run --stop-timeout 35`, or `docker stop -t 35` for one stop.
- Kubernetes: `terminationGracePeriodSeconds: 35`. On other orchestrators, set the equivalent grace period or kill timeout.

When you change `WEB_DRAIN_SECONDS`, change the stop timeout with it.

Docker's default stop timeout is 10 seconds, shorter than even the smallest drain deadline needs (8 + 5 seconds). With it, Docker kills the container 10 seconds into the drain, before the drain window ends. Requests still running then fail, and streams end when their processes die. The web player resumes those streams too, but slow requests lose their responses.

### Listeners during a restart

When its stream fails, or stalls for 10 seconds, the web player asks the server's `/live` endpoint whether the server answers. No answer, a timeout, an answer other than `2xx` or an answer with `"draining": true` means the server is unreachable; nginx answers `502` while the app container restarts, and a draining server can still answer on a connection nginx kept alive. The player then:

- shows "Reconnecting…" in place of the time and keeps the position it had reached;
- retries with a growing, randomised delay, from 1 second up to 15 seconds, and honours a `Retry-After` header on a `503`;
- once `/live` answers, requests the same track again and continues at that position. The resume records no second play.

A transcoded track resumes once its accepted rendition is complete; until then the player shows "Reconnecting…". When the browser will not start playback without a user gesture, the player shows a "Tap to resume" button. After about two minutes without success, the player pauses with its usual failure notice and keeps the position, so pressing play tries again.

A token refresh that fails because the server is restarting, with a network error or a `5xx` answer, keeps the user signed in. Only a `400` or `401` from the refresh signs the user out. Video execution stays with the worker during a web restart; see [Transcoding](transcoding.md#restarts).

### nginx and a replaced app container

The bundled nginx resolves the `app` host name once, when it starts. A restarted container normally keeps its address. A recreated container can come back with a new one. nginx then keeps sending requests to the old address and answers `502`, and the player gives up after two minutes. After the app container has been replaced, reload nginx so it resolves `app` again:

```bash
dc exec nginx nginx -s reload
```

### A proxy in front of nginx

The production nginx expects a reverse proxy in front of it to terminate TLS. When the web server cuts a stream, that proxy must pass the cut on to the client as an error. Over HTTP/2 a proxy can answer an upstream reset by ending the client's stream normally. The player then takes the cut for the end of the track and skips to the next one.

To check your proxy, copy a stream request from the browser's network tab as a curl command and run it through the proxy with `--limit-rate 32k`, so that it is still downloading when you restart the app container. curl must fail with exit code `18` over HTTP/1.1 or `92` over HTTP/2. Exit code `0` means the proxy ended the stream cleanly.

## FFmpeg Updates

FFmpeg runs in the worker deployment for media jobs. Rebuild the worker's immutable
image from the reviewed dependency inputs, then retire the old pair and admit a
fresh boot as above. Installing a binary in web does not update the encoders
already owned by workers. Verify the binary in the selected worker image and
observe a representative playback after replacement; an image version alone
does not establish codec or resource compatibility.

## Version Compatibility

Baander is pre-release and has no production deployment qualification or stable
upgrade compatibility promise. Review migrations, configuration changes and
pending-message compatibility before switching revisions.

When environment variables are added or changed between versions, see [Configuration](configuration.md) for the current list and descriptions.

## Rollback

First establish whether the previous code can read the current schema, queued
messages and worker history. If it cannot, stop writers and recover a compatible
set using [Backup and recovery](backup-and-recovery.md). Retain the failed
deployment's data for diagnosis. Do not try successive old images against the
only copy of a changed database.

For a code-only rollback with verified compatibility, retire the current worker
pair using its matching controller, restore the reviewed previous checkout and
configuration, and rebuild/select the previous images. Follow the same web start,
fresh worker boot and observation sequence as an update.

Changing the code does not revert the database schema. The initial migration is
irreversible, and older code with the former migration chain cannot migrate a
database initialized by it. Restore a backup compatible with the selected code
when a database rollback is required. Any replacement of an existing database
requires its owner's explicit approval for that target; preserve the backup and
follow the [manual reset precautions](#manual-reset-of-an-existing-database).

## Tracking Changes

Baander does not have a formal release notes page yet. To see what changed between versions:

```bash
# View commits since a specific tag
git log --oneline <current-tag>..HEAD

# View recent commits
git log --oneline -20

# View changes to a specific area
git log --oneline -- src/Transcode/
```

Check the commit log for notable changes before upgrading — particularly look for new environment variables in `config/` or schema changes in `migrations/`.

## See Also

- [CLI Reference](commands/README.md) -- all available console commands
- [Configuration](configuration.md) -- environment variables that may change between versions
