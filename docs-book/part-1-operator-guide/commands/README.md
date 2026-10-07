# CLI Commands

Baander ships console commands for managing users, libraries, metadata and scheduler jobs. The console commands below run inside the app container. Worker deployment uses a separate host entrypoint.

```bash
# Run any command
make exec cmd="php bin/console <command>"

# Example
make exec cmd="php bin/console app:user:create --help"
```

## Worker deployment

Run `bin/worker-deployment.php` from a trusted host checkout with Composer dependencies,
PHP 8.5 with extensions `posix` and `pdo_pgsql`, and an absolute Docker executable connected
to a local Unix socket. The application container receives no Docker authority.
Provision the migrated database, Redis and a private Docker network first; use a
locally available immutable image ID. The initial recipe has no mounts or devices.

The manifest is a JSON object of at most 8192 bytes. Version 1 requires exactly
these fields, with JSON integers for version and resource values:

```json
{
  "version": 1,
  "namespace": "worker.baander.app",
  "bootId": "0123456789abcdef0123456789abcdef",
  "daemonId": "replace-with-docker-info-ID",
  "imageId": "sha256:0000000000000000000000000000000000000000000000000000000000000000",
  "network": "baander-workers",
  "dockerBinary": "/usr/bin/docker",
  "dockerEndpoint": "unix:///var/run/docker.sock",
  "memoryMiB": 1280,
  "managementMiB": 128,
  "consumerMiB": 320,
  "relayMiB": 320,
  "schedulerMiB": 320,
  "scheduledConsoleMiB": 192,
  "nanoCpus": 1000000000,
  "pidsLimit": 64
}
```

Replace the daemon/image examples with `docker info --format '{{.ID}}'` and the
local image's `docker image inspect --format '{{.Id}}'` values on the same endpoint.
Generate a fresh 32-character lowercase hexadecimal boot ID for each deployment
attempt. Reservations must fit the memory ceiling: management requires at least
128 MiB; consumer, relay and scheduler each require at least 320 MiB. Scheduled
console execution requires at least 192 MiB or `0` to disable it. The worker argv,
PHP management limit, identity and lock directory are fixed by the manifest parser;
arbitrary commands, environment fields and secret paths are rejected.

Keep credentials in a separate canonical absolute regular file owned by the
invoking user, with mode `0600`, no symlink components and at most 8192 bytes:

```json
{
  "controllerDatabaseUrl": "postgresql://worker:replace-password@db-control.baander.app/baander?serverVersion=18&charset=utf8",
  "runtimeEnvironment": {
    "APP_ENV": "prod",
    "APP_DEBUG": "0",
    "APP_SECRET": "replace-with-runtime-secret",
    "DATABASE_URL": "postgresql://worker:replace-password@db-worker.baander.app/baander?serverVersion=18&charset=utf8",
    "REDIS_URL": "redis://:replace-password@redis.baander.app:6379",
    "MESSENGER_TRANSPORT_DSN": "redis://:replace-password@redis.baander.app:6379/messages"
  }
}
```

The two database addresses must reach the same database from the host and worker
network respectively. The controller URL and credentials file stay on the host.
Docker receives the admitted runtime values through a temporary private env-file;
trusted daemon operators can inspect the resulting container environment.
Optional runtime keys are `REDIS_PASSWORD` and `MAILER_DSN`; values cannot contain
line breaks and their complete env-file is limited to 4 KiB.

Apply an external deadline to every action. DBAL does not enforce a hard connection
deadline through `connect_timeout`; that query option is rejected here.

```bash
php bin/worker-deployment.php --help
timeout --kill-after=5s 60s php bin/worker-deployment.php create /etc/baander/worker-manifest.json /etc/baander/worker-credentials.json
timeout --kill-after=5s 60s php bin/worker-deployment.php start /etc/baander/worker-manifest.json /etc/baander/worker-credentials.json
timeout --kill-after=5s 60s php bin/worker-deployment.php status /etc/baander/worker-manifest.json /etc/baander/worker-credentials.json
```

`create` commits one creation intent before Docker work. If its reply is uncertain,
use `reconcile-create` with the identical manifest and credentials; it verifies and
registers the existing never-started container without creating another. `start`
consumes a one-shot claim before issuing Docker start. An uncertain start requires
explicit `recover`, never another start. Recovery retires that immutable boot,
verifies removal and releases only its reservation. Keep the old manifest for
recovery; a replacement needs a fresh boot ID. `status` reports committed
observations and always returns `readiness: "not_checked"`; it does not certify
application readiness. Timeout or interruption does not cancel an operation
already submitted to Docker or authorize a retry.

Actions return bounded JSON on stdout. Exit codes are `0` for a confirmed result,
`1` for `operation_unconfirmed`, `2` for `invalid_configuration`, and `3` for a
denied or unregistered operation. The external `timeout` command may return its
own exit code without JSON. Error output contains no credentials or raw diagnostics.

## Auth & Users

| Command | Description |
|---------|-------------|
| [app:auth:rotate-secrets](app-auth-rotate-secrets.md) | Prepare an OAuth bundle and invalidate grants during offline cutover |
| [app:auth:setup-clients](app-auth-setup-clients.md) | Create the first-party OAuth client that password and passkey login issue tokens to |
| [app:oauth:generate-keys](app-oauth-generate-keys.md) | Generate OAuth2 private and public keys for JWT signing |
| [app:user:create](app-user-create.md) | Create a new user account |
| [app:user:disable](app-user-disable.md) | Disable a user account |
| [app:user:enable](app-user-enable.md) | Enable a previously disabled user account |
| [app:user:reset-password](app-user-reset-password.md) | Set a new password for a user and sign them out everywhere |

## Libraries & Catalog

| Command | Description |
|---------|-------------|
| [app:library:create](app-library-create.md) | Register a new media library |
| [app:library:scan](app-library-scan.md) | Scan a media library for new files |
| [app:albums:extract-covers](app-albums-extract-covers.md) | Extract embedded cover art for albums missing one |
| [app:watch-files](app-watch-files.md) | Watch directories for filesystem changes |

## Media & Lyrics

| Command | Description |
|---------|-------------|
| [app:images:prune-missing](app-images-prune-missing.md) | Remove image records whose files no longer exist on disk |
| [baander:lyrics:fetch](baander-lyrics-fetch.md) | Bulk-fetch lyrics from LRCLIB for songs missing them |

## Radio

| Command | Description |
|---------|-------------|
| [app:radio:sync](app-radio-sync.md) | Sync stations for all subscribed countries |

## Recommendations

| Command | Description |
|---------|-------------|
| [app:recommendations:generate](app-recommendations-generate.md) | Generate music recommendations using all available strategies |

## Scheduler

| Command | Description |
|---------|-------------|
| [app:scheduler:list](app-scheduler-list.md) | List all scheduled jobs |
| [app:scheduler:run](app-scheduler-run.md) | Manually trigger a scheduled job by ID |

## Notifications

| Command | Description |
|---------|-------------|
| [app:generate-vapid-keys](app-generate-vapid-keys.md) | Generate VAPID keys for push notifications |

## System & Diagnostics

| Command | Description |
|---------|-------------|
| [app:config:validate](app-config-validate.md) | Validate application configuration and check for misconfigurations |
| [app:health:check](app-health-check.md) | Check the health of all system components |
| [app:cli:manifest](app-cli-manifest.md) | Output a JSON manifest of all CLI commands and tooling metadata |
| [app:monitor:prune](app-monitor-prune.md) | Prune completed job monitors older than a given age |
| [debug:hw-transcode](debug-hw-transcode.md) | Show resolved hardware encoder profile and sample FFmpeg commands |

### Failed messages

Messages that exhaust their retries land in the failure transport, a PostgreSQL table. Symfony's built-in commands manage it by ID, and the admin endpoints under `/api/monitor/transport/failed` use the same receiver (see [Monitoring](../monitoring.md#failed-messages)). Pass `--force` to skip the confirmation prompt, which non-interactive runs require.

| Command | Description |
|---------|-------------|
| `messenger:failed:show` | List failed messages, or show one with `messenger:failed:show <id>` |
| `messenger:failed:retry <id> --force` | Handle a failed message again; one that fails again returns under a new ID and stays listed |
| `messenger:failed:remove <id> --force` | Remove a failed message; `--all` removes every message the transport can deliver now |

## Development & Docs

| Command | Description |
|---------|-------------|
| [app:dev:setup](app-dev-setup.md) | Bootstrap the dev environment (migrations, keys, clients, users) |
| [app:dev:create-users](app-dev-create-users.md) | Create the standard development users |
| [app:export-openapi-spec](app-export-openapi-spec.md) | Export the OpenAPI spec to a file |
| [app:generate-docs](app-generate-docs.md) | Generate the documentation site from source code and docs-book/ |
