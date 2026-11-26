# Upgrading

An upgrade changes three things independently: the web image and checkout, the
database schema, and the admitted worker pair. Plan an outage for writers when
the schema or message contract changes. A healthy web response alone does not
show that the new workers can process the old pending work.

## Standard Update

The deployment preset replaces an installation in one action, once the new images
exist: `start` retires the old worker pair, stops the saved services, renders the
new release, runs pending migrations and starts web and a fresh worker pair. Run
these steps on the installation host, from its checkout, in the shell where the
installation's [`dc` function](../getting-started/installation.md#command-context)
is defined. The development Make lifecycle commands target isolated development
instances; they do not operate this installation.

1. **Record the current state and preserve it.** Record the current revision, the
   output of `php scripts/deploy-preset.php status --state /srv/baander/preset-state`
   and a copy of the private configuration. Follow
   [Backup and recovery](backup-and-recovery.md) to preserve a consistent recovery
   set; a code checkout is not a database backup. Keep the state directory: `start`
   reads the saved worker record there to retire the old pair.
2. **Select the reviewed target revision.** Update the checkout to it, keeping the
   private configuration and the state directory outside the checkout. Install the
   host tooling for that revision as in
   [Installation](../getting-started/installation.md#1-install-the-host-tooling).
3. **Build the new images.** Build the web frontend, then the images, from that
   revision as [Installation](../getting-started/installation.md#2-build-the-web-frontend)
   and its [image step](../getting-started/installation.md#3-build-the-images)
   describe. The new images may carry the tags the configuration already names:
   rendering resolves each tag to an immutable image ID. The application, worker
   and operator images must declare the same source revision, or rendering refuses
   them. Change `images` in the configuration only when the tags change.
4. **Replace the deployment.** Stop incoming writes, then run:

   ```bash
   php scripts/deploy-preset.php start --config /srv/baander/preset.json --state /srv/baander/preset-state
   ```

   `start` first retires the saved worker pair and stops the saved services. It
   then renders the new release, starts PostgreSQL, Redis and the connection
   pooler, and runs pending migrations through the pooler before web starts. A
   failed migration stops `start` before web or a worker pair runs; do not start
   old code against a partially changed schema. When `start` fails, the saved phase
   and worker record remain in the state directory: correct the cause and run
   `start` again. Replacing or upgrading PostgreSQL itself requires a
   database-version-specific procedure; `start` does not perform a PostgreSQL
   major-version upgrade.
5. **Check before reopening access.** `start` returns after the new worker pair
   reports ready. Then run:

   ```bash
   php scripts/deploy-preset.php status --state /srv/baander/preset-state
   dc exec web php bin/console app:config:validate
   dc exec web php bin/console app:health:check
   ```

   `status` reports the phase, whether the worker pair runs, and each service.
   Inspect transport state, sign in, and test the media path needed by your users.
   [Monitoring](../operations/README.md) distinguishes these checks and their
   limits. Reopen access only after the relevant checks pass.

Check the commit log for notable changes between your current version and the one you are upgrading to:

```bash
git log --oneline <current-tag>..HEAD
```

A change of capability or size takes effect the same way: set it in the
configuration and run `start`, which retires the old pair and stops the saved
services before starting the selected release. `stop` retains named data volumes.
See [Deployment capability and size](../configuration/deployment-profiles.md#deployment-capability-and-size).

A preset started with the earlier household tooling cannot be replaced this way.
Its state directory, configuration format and volume ownership labels belong to that
tooling, and `scripts/deploy-preset.php` refuses them. Stop it with the checkout
that started it, then start the new preset under a fresh name and state directory;
move data only through [backup and recovery](backup-and-recovery.md).

### Worker pairs managed outside the preset

A worker pair run directly with the host
[deployment controller](../workers/worker-deployment.md) is retired and replaced
by that controller, not by `start`.

Before pulling this update, retire any singleton worker deployment with the old
checkout, controller, manifest and credentials. Stop controller activity before
applying migrations. The paired-deployment migration refuses active leases,
unresolved creations, incomplete retirements or inconsistent singleton history;
lease expiry is insufficient. Resolve these through the old controller while its
schema is still installed. Preserve the database, pending work and media; no reset
is required or authorized by this upgrade.

Version 9 manifests require `sourceMountPolicy`, the complete startup `deploymentProfile`, the ordinary autoscaling profile and `deviceGroup`. They carry no per-unit memory, CPU, PID or CPU-set fields. The profile names its `capability`, `transcode` or `remux`, in place of the former profile identifier. The selected startup profile must agree with web and the worker runtime environment, which carry it as JSON in the single `BAANDER_DEPLOYMENT_PROFILE` variable. The new controller refuses earlier manifest versions, including recovery actions.

Keep the old checkout, controller, manifest and credentials to retire an existing pair before switching code. Confirm both units' retirement and the joint reservation release, then create a version 9 manifest with a fresh boot ID, the new image and `sourceMountPolicy: production` for the named-volume recipe. Lease expiry or a stopped container is insufficient. Preserve the database, pending work and media. This manifest change requires no data reset and provides no compatibility path.

Such a worker uses an immutable image and boot identity. Keep the old controller
available until both units are confirmed retired, and stop if retirement is
unconfirmed. Then create and start a new boot with the updated image and manifest.
Never enable automatic worker restart or place its children under another
supervisor. Prepare shared media volumes before starting either role, and
preserve pending work and existing media.

## Queued Messages and Retry Data

Before upgrading an instance with older queued messages:

1. Stop producers and preserve queued and retrying Redis work while retiring the
   old worker deployment. The preset's `stop` and `start` retire it; a pair managed
   outside the preset retires through the [deployment controller](../workers/worker-deployment.md).
2. Preserve Redis streams and the PostgreSQL database, which contains failed
   messages, outbox rows and job history. See the
   [recovery inventory](backup-and-recovery.md#choose-the-recovery-set). Removing
   failed messages discards work; it is not a backup operation.
3. Upgrade producers and workers together, following the
   [standard update](#standard-update), which retires the old pair and starts a
   fresh boot.

Queued commands and stored job retry data use the `baander.message` JSON format,
version 1. PHP-serialized envelopes and legacy `__class` job payloads are rejected;
archived legacy jobs cannot be retried through the new codec. Do not remove
pending messages to make an upgrade pass. See [CQRS and Messaging](../../part-2-developer-guide/development/cqrs-and-messaging.md#message-contract-and-retries)
for the contract and retry behavior.

## Database Migrations

`deploy-preset.php start` runs
`php bin/console doctrine:migrations:migrate --no-interaction` on the configured
main database before web starts, in a one-off application container that reaches
PostgreSQL through the connection pooler. Every update therefore applies the
pending migrations of the new code; they may contain data transformations or index
additions. Test databases belong to the separate disposable test coordinator.

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
   the processes that can write to the approved target. The preset's `stop` stops
   PostgreSQL with them; it has no action that runs PostgreSQL without web and the
   worker pair.
3. **Recreate only the approved database manually.** Preserve its database creation
   settings. Leave other databases, PostgreSQL volumes, Redis, registry storage
   and media files untouched. Do not use a reset helper that targets more than the approved database.
4. **Initialize and verify.** Run Doctrine migrations against that empty target,
   then run them again and verify there is no pending work. For the configured
   main database alone, `deploy-preset.php start` runs the first migration before
   web starts; run the second with
   `dc exec web php bin/console doctrine:migrations:migrate --no-interaction`.
5. **Restore service state.** Restart the services recorded in step 2, which
   `start` has done for the preset, and complete the
   [initial setup](../getting-started/installation.md#6-create-the-sign-in-client-and-the-first-account)
   required by the empty application database.

## When to Rebuild Docker Images

Rebuild the affected image when any of the following have changed:

- The `Dockerfile` or `.dockerignore`
- PHP extensions (added or removed in the Dockerfile)
- System packages in the Dockerfile (e.g., `apt-get install`)
- The base image tag

Build the application, worker and operator images with
`scripts/build-application.php`, and the PostgreSQL image, as
[Installation](../getting-started/installation.md#3-build-the-images) describes.
A successful build does not replace running services or migrate their data; the
next `start` renders the new image IDs, recreates the services, migrates and starts
a fresh worker pair. Build inputs and cache settings are described in the
[development environment guide](../../part-2-developer-guide/getting-started/development-environment.md).

PHP dependency updates also require a clean Composer installation from the reviewed
locks, including retained patches, and new images for each affected role. The
selected stack uses PHP 8.5.13, Swoole 6.2.4 and Composer 2.11.1. An updated checkout
does not change the runtime or dependencies already loaded by a worker. Replace
the deployment with `start`, as in the [standard update](#standard-update), to
load the new images. Maintainers can
check version drift and patch status through
[PHP dependency maintenance](../../part-2-developer-guide/getting-started/php-dependencies.md);
those checks do not replace application startup, delivery or drain qualification.

## Moving to the Debian 13 images

See [Moving to the Debian 13 images](debian-13-upgrade.md#moving-to-the-debian-13-images).

## Service Definition Changes

The preset renders its private Compose document from the configuration and the
images each time `start` runs, and keeps it in the state directory; the checkout's
`docker-compose.yml` is not part of the installation. A change to services, volume
mounts, networks or environment variables, whether it comes with new code or from
the configuration, takes effect only through `start`, which stops the saved
services and creates them again from the new document. Restarting a running
container keeps its old definition and environment.

Recreating Redis interrupts work in progress. The preset keeps Redis data in its
named volume with the append-only file on; still preserve that state before the
change. `stop` and `start` retain every named volume. Never remove them, for
example with `docker compose down -v`, as an upgrade step.

## Stopping and Restarting the Web Server

See [Stopping and Restarting the Web Server](web-server-restarts.md#stopping-and-restarting-the-web-server).

## FFmpeg Updates

FFmpeg runs in the worker deployment for media jobs. Rebuild the worker's immutable
image from the reviewed dependency inputs, then replace the deployment with `start`
as above, which retires the old pair and starts a fresh boot. Installing a binary in web does not update the encoders
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

For a code-only rollback with verified compatibility, restore the reviewed
previous checkout and configuration, rebuild or select the previous images, and
run `start` as in an update: it retires the current worker pair before it starts
the previous release. Follow the same observation sequence as an update.

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
