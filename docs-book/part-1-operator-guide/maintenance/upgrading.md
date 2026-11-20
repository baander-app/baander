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

Version 9 manifests require `sourceMountPolicy`, the complete startup `deploymentProfile`, the ordinary autoscaling profile and `deviceGroup`. They carry no per-unit memory, CPU, PID or CPU-set fields. The profile names its `capability`, `transcode` or `remux`, in place of the former profile identifier. The selected startup profile must agree with web and the worker runtime environment, which carry it as JSON in the single `BAANDER_DEPLOYMENT_PROFILE` variable. The new controller refuses earlier manifest versions, including recovery actions.

Keep the old checkout, controller, manifest and credentials to retire an existing pair before switching code. Confirm both units' retirement and the joint reservation release, then create a version 9 manifest with a fresh boot ID, the new image and `sourceMountPolicy: production` for the named-volume recipe. Lease expiry or a stopped container is insufficient. Preserve the database, pending work and media. This manifest change requires no data reset and provides no compatibility path.

The source-installation procedure below uses the `dc` shell function. For the
managed complete-stack preset, keep its private state directory and use
`scripts/deploy-preset.php` to replace the deployment. Set the capability and size
in its configuration; a later `start` retires the old pair and stops the saved
services before starting the selected release. `stop` retains named data volumes.
See [Deployment capability and size](../configuration/deployment-profiles.md#deployment-capability-and-size).

A preset started with the earlier household tooling cannot be replaced this way.
Its state directory, configuration format and volume ownership labels belong to that
tooling, and `scripts/deploy-preset.php` refuses them. Stop it with the checkout
that started it, then start the new preset under a fresh name and state directory;
move data only through [backup and recovery](backup-and-recovery.md).

Use the `dc` function and private environment file defined in
[Getting Started](../getting-started/installation.md#1-prepare-configuration-and-the-browser-address).
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

Keep the web service stopped while applying a schema change. With PostgreSQL, its
connection pooler and Redis available, update dependencies in the mounted checkout and migrate through
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
recipe, use the host [deployment controller](../workers/worker-deployment.md)
to recover and retire the old deployment, then create/start a new boot with the
updated image and manifest. Never enable automatic worker restart or place its
children under another supervisor. Prepare shared media volumes before starting
either role, and preserve pending work and existing media.

Start and observe the fresh worker pair before allowing new work. Check its
explicit readiness observation, inspect transport state, sign in, and test the
media path needed by your users. [Monitoring](../operations/README.md) distinguishes these
checks and their limits. Reopen access only after the relevant checks pass.

Check the commit log for notable changes between your current version and the one you are upgrading to:

```bash
git log --oneline <current-tag>..HEAD
```

## Queued Messages and Retry Data

Before upgrading an instance with older queued messages:

1. Stop producers and preserve queued and retrying Redis work while retiring the
   old worker deployment through the [deployment controller](../workers/worker-deployment.md).
2. Preserve Redis streams and the PostgreSQL database, which contains failed
   messages, outbox rows and job history. See the
   [recovery inventory](backup-and-recovery.md#choose-the-recovery-set). Removing
   failed messages discards work; it is not a backup operation.
3. Upgrade producers and workers together, following the worker retirement and
   fresh-boot procedure above.

Queued commands and stored job retry data use the `baander.message` JSON format,
version 1. PHP-serialized envelopes and legacy `__class` job payloads are rejected;
archived legacy jobs cannot be retried through the new codec. Do not remove
pending messages to make an upgrade pass. See [CQRS and Messaging](../../part-2-developer-guide/development/cqrs-and-messaging.md#message-contract-and-retries)
for the contract and retry behavior.

## Database Migrations

`dc exec app php bin/console doctrine:migrations:migrate --no-interaction` runs
pending migrations on the configured main database. Run it after pulling new code:
migrations may contain data transformations or index additions. Test databases
belong to the separate disposable test coordinator.

Fresh databases start with the transactional migration `Version20261017000000`, then apply the forward migrations that follow it in `migrations/`. The initial migration creates the schema, including microsecond `timestamptz` columns, and cannot be downgraded. The first forward migrations add playlist persistence versions and video/audio execution authority. `Version20261017160000` preserves durable session/request control receipts; `Version20261017170000` scopes video jobs to playback owners without rewriting the initial migration or existing histories. `Version20261018130000` imports confirmed singleton retirement history and adds the control/execution journals and joint retirement barrier. It is irreversible.

`Version20261021120000` retains the last observed availability of indexed source files and is also irreversible, as are most of the forward migrations after it. New deployments require a version 9 manifest with a private IPC volume and complete deployment and autoscaling profiles, and with no unit limits or CPU sets; earlier manifest versions are refused. Every profile field is fingerprinted in both recipes. Changing thresholds, concurrency or the startup profile requires retirement and a fresh boot, with matching web and worker runtime settings.

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
   the [initial setup](../getting-started/installation.md#5-initialize-the-main-database-and-authentication) required by the empty
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
the [development environment guide](../../part-2-developer-guide/getting-started/development-environment.md).

PHP dependency updates also require a clean Composer installation from the reviewed
locks, including retained patches, and new images for each affected role. The
selected stack uses PHP 8.5.12, Swoole 6.2.4 and Composer 2.11.1. An updated checkout
does not change the runtime or dependencies already loaded by a worker. Follow
the retirement and fresh-boot sequence above when replacing it. Maintainers can
check version drift and patch status through
[PHP dependency maintenance](../../part-2-developer-guide/getting-started/php-dependencies.md);
those checks do not replace application startup, delivery or drain qualification.

## Moving to the Debian 13 images

See [Moving to the Debian 13 images](debian-13-upgrade.md#moving-to-the-debian-13-images).

## Docker Compose Changes

When `docker-compose.yml` changes (new services, new volume mounts, network changes, environment variable additions), `dc restart` is not enough: Docker Compose must recreate the affected containers.

Recreate only the affected services after checking their volumes, ports and
credentials with the installation's Compose context. Use `dc up -d --no-deps app`
for an application-only change. A dependency recreation can interrupt work or
lose unpersisted Redis state; preserve that state before the change. Never use
`down -v` as an upgrade step.

## Stopping and Restarting the Web Server

See [Stopping and Restarting the Web Server](web-server-restarts.md#stopping-and-restarting-the-web-server).

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

When environment variables are added or changed between versions, see [Configuration](../configuration/README.md) for the current list and descriptions.

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

- [CLI Reference](../commands/README.md) -- all available console commands
- [Configuration](../configuration/README.md) -- environment variables that may change between versions
