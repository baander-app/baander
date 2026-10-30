# Getting Started

This guide prepares a new Bånder instance from source, creates an administrator and
scans a first library. The current Compose stack is development-oriented: it mounts
the checkout into the web container and uses a local TLS certificate. Background
processing needs a separate worker deployment. Read [Understanding Baander](understanding-baander.md)
for the component roles and [Storage and data](storage-and-data.md) before choosing
volumes or importing media.

A disposable installation verified frontend loading, administrator login, worker
startup and a sample music scan through ingestion and browser catalog display.
That checkpoint predates the current application-image build interface below; it
does not verify this revision of the installation procedure.
The scanned track played to completion as original FLAC and transcoded Opus;
byte-range requests also passed. See the
[bootstrap checkpoint](../../ROADMAP.md#source-installation-documentation) for
evidence, environment adaptations and verification limits.

## Prerequisites

- Git, Docker with Compose and Buildx, and OpenSSL for generating secrets.
- A Linux Docker host with cgroup v2, a local Docker Unix socket and enough CPU and
  memory for web, PostgreSQL, Redis and the worker pair. The worker guide's example
  reserves 2944 MiB for control and 4800 MiB for execution; admission also requires
  1 GiB of host memory beyond those ceilings and disjoint CPU sets.
- Host PHP 8.5 with Swoole for image-build tooling, Composer, and `posix` and
  `pdo_pgsql` for the worker controller. Tooling and application dependencies are
  installed separately below. The controller runs on the
  trusted host; the application containers receive no Docker socket.
- Media files that you can copy into a shared named Docker volume. Worker admission
  does not accept host-directory bind mounts for media.

FFmpeg, Node and Corepack are supplied by the app image. The web frontend selects
its Yarn version from the repository's `packageManager` field.

Read [Worker deployment](commands/README.md#worker-deployment) before provisioning
storage or choosing host resources. Its version-5 procedure is authoritative for
the worker manifest, credentials, volume ownership and admission checks.

## Command context

Run installation commands on the trusted Docker host, from this checkout. The
`dc` function defined below selects this source installation and its private
configuration. Later chapters use `dc exec app php bin/console …` in that same
shell, once the app is running. A plain `php bin/console …` example applies only
inside an application container in its application directory.

The worker controller, `php bin/worker-deployment.php`, runs on the trusted host.
Its database URL must be reachable from that host; worker URLs must be reachable
from the worker containers. Do not mount Docker's socket into the web application.

Make commands select the checkout's development preview by default.
`INSTANCE=persistent` selects a persistent development instance. Neither selects
this source installation. See [Which installation a command targets](understanding-baander.md#which-installation-a-command-targets)
and the [development environment guide](../part-2-developer-guide/development-environment.md).

## Installation

Run the following steps from the repository root. Use a fresh checkout and fresh
instance resources. For an existing installation, follow [Upgrading](upgrading.md)
instead; do not replace its database or media volumes.

### 1. Prepare configuration and the browser address

Clone the repository from its Git remote, then enter the checkout:

```bash
git clone <repository-url> baander
cd baander
cp .env.example .env
```

Keep `.env` free of deployment secrets: the production and worker image builds copy
it into the image. In `.env`, set the media paths and the executables supplied by the
image's Debian packages:

```env
MEDIA_STORAGE_PATH=/media/library
CONVERT_STORAGE_PATH=/storage/conversions
FFMPEG_PATH=/usr/bin/ffmpeg
FFPROBE_PATH=/usr/bin/ffprobe
VIDEO_ENCODER=libx264
HW_ACCEL_DEVICE=
HW_BITRATE_MULTIPLIER=1.0
```

The encoder settings above select CPU encoding. Set them before building the
worker image: its media process requires all three, and the worker credentials
file does not accept these settings. See
[Transcoding](transcoding.md#hardware-acceleration) for current hardware support.

Create `.env.dev.local` with mode `0600`. Put the following settings in it, replacing
the secret placeholders with independently generated values from `openssl rand -hex 32`.
Use the same database password in `DB_PASSWORD` and `DATABASE_URL`, and the same
Redis password in all three Redis settings:

```env
APP_SECRET=<chosen-random-secret>
STREAM_URL_HMAC_SECRET=<another-random-secret>
DB_USERNAME=baander
DB_PASSWORD=<chosen-database-password>
DB_DATABASE=baander
DATABASE_URL="postgresql://baander:<chosen-database-password>@database:5432/baander?serverVersion=18&charset=utf8"
REDIS_PASSWORD=<chosen-redis-password>
REDIS_URL=redis://default:<chosen-redis-password>@redis:6379
MESSENGER_TRANSPORT_DSN=redis://default:<chosen-redis-password>@redis:6379/messages
SERVER_NAME=baander.test
APP_DOMAIN=baander.test
APP_URL=https://baander.test:8443
DEFAULT_URI=https://baander.test:8443
WEB_PORT_HTTP=8080
WEB_PORT_TLS=8443
AUTH_SPA_CLIENT_ID=baander_dev_spa_00001
```

Symfony loads `.env`, `.env.local`, `.env.dev`, then `.env.dev.local` in this
configuration; existing process environment variables take precedence. The tracked
`.env.dev` contains a development `APP_SECRET`, so putting your secret only in `.env`
or `.env.local` does not override it. `.env.dev.local` is excluded from Git and Docker
build contexts. Set the complete connection URLs there so they use the same chosen
credentials as the services.

Compose has its own environment loading. Use this shell function for every Compose
command below so it reads the private settings and the image selection explicitly.
Step 3 generates `.env.compose.local` before the first `dc` invocation:

```bash
export HOST_UID="$(id -u)"
export HOST_GID="$(id -g)"

dc() {
    docker compose --env-file .env.dev.local --env-file .env.compose.local -f docker-compose.yml "$@"
}
```

Run it in the same shell as the remaining commands. Remove conflicting exported
variables before using it; shell values override Compose's environment file. The
Makefile shortcuts target a different development workflow.

Resolve `baander.test` to the Docker host in your local DNS or hosts file. The bundled
development certificate covers `baander.test`, its subdomains and `127.0.0.1`.
Trust that certificate in your development browser, or configure a certificate
trusted by your clients before using the instance. Changing `SERVER_NAME` alone does
not change the certificate. See [Security](security.md) for deployment hardening.

### 2. Prepare the network and shared storage

The Compose file expects an external network:

```bash
docker network create baander-backtier
```

If that name already exists, inspect it before choosing it for this instance. The
Compose file also has fixed container names and published database and Redis ports.
Choose non-conflicting names and ports before starting another instance on the same
host; a Compose project name alone does not isolate these settings.

Provision the named media volumes using the local driver without driver options:

```bash
docker volume create baander-source
docker volume create baander-conversions
docker volume create baander-storage
docker volume create baander-transcode-state
```

In your checkout's `docker-compose.yml`, replace the personal `/home/martin/...`
bind mounts in `app.volumes` with these shared mounts. Keep the checkout and PHP
configuration mounts:

```yaml
services:
    app:
        volumes:
            - ./:${APP_HOME:?Use the generated build --compose-env file}:cached
            - ./docker/dev/php.ini:/usr/local/etc/php/php.ini:ro
            - ./docker/dev/swoole.ini:/usr/local/etc/php/conf.d/docker-php-ext-swoole.ini:ro
            - baander-source:/media:ro
            - baander-conversions:/storage/conversions
            - baander-storage:${APP_HOME:?Use the generated build --compose-env file}/storage
            - baander-transcode-state:${APP_HOME:?Use the generated build --compose-env file}/var/transcode_state
volumes:
    baander-source:
        external: true
    baander-conversions:
        external: true
    baander-storage:
        external: true
    baander-transcode-state:
        external: true
```

Merge these entries into the existing services and volumes; retain the database
volume declaration. Copy the media you want to scan into `library/music` inside
`baander-source`. Keep originals intact. Web and workers must see the same paths;
set `MEDIA_STORAGE_PATH=/media/library` in both roles. Source media is read-only
in workers. The web mount above is also read-only; authorized deletion of media
files through web requires writable access there.

Prepare the derived volumes for the runtime UID/GID of the images you build, so
web and workers can write them. Follow the worker guide for ownership checks and
its separate private IPC volume; never mount that IPC volume into web.

### 3. Build images and start dependencies

Install the standalone host tooling, then build the development application image.
The builder reads the application's layout contract and writes its image ID and
`APP_HOME` into `.env.compose.local`; Compose requires both values. This generated
file selects the build and contains no application credentials.

```bash
composer --working-dir=scripts install --no-dev --no-interaction
php scripts/build-application.php --target dev --tag baander:local --compose-env .env.compose.local
dc config --quiet
dc build nginx database
dc up -d --wait database redis
dc exec redis sh -c 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli ping'
```

The Redis check must print `PONG`. Redis receives its password on its first startup.
The app and nginx are still
stopped. If a build or dependency fails, inspect its error before continuing.

### 4. Install PHP dependencies

A new checkout has no `vendor/` directory, so use a one-off container rather than
executing a command in a running web container:

```bash
dc run --rm --no-deps app composer install --no-interaction --no-progress --no-scripts
dc run --rm --no-deps app composer dump-autoload --optimize --no-interaction
```

The separate dump runs the Symfony Runtime autoload hook, generating
`vendor/autoload_runtime.php`. Do not add `--no-scripts` to that second command.
The first command defers cache and asset scripts until configuration is ready.

### 5. Initialize the main database and authentication

```bash
dc run --rm --no-deps app php bin/console doctrine:migrations:migrate --no-interaction
dc run --rm --no-deps app php bin/console app:oauth:generate-keys --no-interaction
dc run --rm --no-deps app php bin/console app:auth:setup-clients
```

The migration command initializes only the configured main database and retains the
initial migration's empty-database guard. See [Database migrations](upgrading.md#database-migrations)
for existing histories and the separately approved reset procedure.

Key generation is for this new instance. Keep its private key private; use the
[security guide](security.md) for later key rotation. Confirm that
`AUTH_SPA_CLIENT_ID` in `.env.dev.local` matches the ID printed by client setup.

Create an administrator interactively:

```bash
dc run --rm --no-deps app php bin/console app:user:create admin@baander.app Admin --role admin
```

Choose a password at the hidden prompt and confirm the administrator role. See
[User management](user-management.md) and [app:user:create](commands/app-user-create.md)
for password rules and scripted creation.

### 6. Install and build the web frontend

Use the image's Node/Corepack tooling from the frontend directory. Corepack selects
the pinned Yarn version, currently 4.14.1:

```bash
dc run --rm --no-deps app sh -c 'cd "$APP_HOME/ui/web" && corepack yarn install --immutable'
dc run --rm --no-deps app sh -c 'cd "$APP_HOME/ui/web" && corepack yarn build'
```

The build type-checks the frontend and writes its assets into `public/`, where the
web application loads them.

### 7. Start web and deploy workers

```bash
dc up -d app nginx
```

Nginx terminates HTTPS and forwards requests to the app over HTTP. Configure the
app to trust that proxy's actual source address so generated API URLs retain HTTPS
and the published TLS port. Inspect nginx on the network prepared above:

```bash
docker inspect --format '{{(index .NetworkSettings.Networks "baander-backtier").IPAddress}}' "$(dc ps -q nginx)"
```

If you chose another network name, substitute it in the inspection command. Add
the returned address to `.env.dev.local` as a single `TRUSTED_PROXIES` setting:

```env
TRUSTED_PROXIES=<nginx-source-ip>
```

Then restart the app to load it:

```bash
dc restart app
```

Trust only the actual proxy address; do not trust the entire Docker network.
Both HTTP layers default to no proxy trust, which is appropriate for direct client
access. If nginx is recreated, inspect its address again, update the setting and
restart app. See [Configuration](configuration.md#rate-limiting) for the shared
proxy trust setting.

Follow [Worker deployment](commands/README.md#worker-deployment) to build the worker
image, prepare its private IPC volume and credentials, and create and start the
version-5 deployment from the host. First export the application layout selected by
the builder in that host shell; the controller requires it when interpreting the
manifest:

```bash
export APP_HOME="$(sed -n 's/^APP_HOME=//p' .env.compose.local)"
```

The current layout is `/var/www/html`. Use the network and media volumes prepared above;
the host and worker database URLs must reach the same migrated database. Supply the
same application secret and Redis credentials through the worker credentials file.
Worker runtime uses `APP_ENV=prod` and does not load the web's `.env.dev.local`.

The admitted worker pair owns background consumption, scheduling, outbox relay and
media execution. Compose starts none of these. Check worker logs and operational
health as described in that guide; controller `status` reports
`readiness: "not_checked"` and is not a readiness test. The separate controller
`readiness` action observes the admitted pair; see [Observe background work](background-work.md#observe-background-work).

### 8. Create and scan a library

Once media is present at `/media/library/music`, create a music library with its
required media type and an explicit slug:

```bash
dc exec app php bin/console app:library:create "My Music" /media/library/music music --slug my-music
dc exec app php bin/console app:library:scan my-music
```

The console scan discovers files in that process, records its work in the job
monitor and queues directories for ingestion. Workers must finish ingestion before
the media appears in the catalog; a completed scan alone does not establish that
background processing works. Check worker health and ingestion progress using
[Monitoring](monitoring.md), then browse the library as the administrator. Grant
other users access separately. See [Library management](library-management.md) for
library access and scanning.

### 9. Open Bånder

Open **https://baander.test:8443** and log in with the administrator account you
created. Use the explicit HTTPS address: the development HTTP redirect does not
preserve a custom TLS port. If you choose another port, change `WEB_PORT_TLS`,
`APP_URL` and `DEFAULT_URI` together.

## Check the installation

Check the web and background paths separately before giving users access:

1. Open the HTTPS address above and sign in. This checks the browser, proxy and
   authentication path for that account.
2. Inspect web readiness and component health using [Monitoring](monitoring.md).
   The web readiness check tests PostgreSQL and Redis; it does not establish worker
   readiness or that external integrations work.
3. Run the controller's read-only `readiness` action for the exact worker manifest
   and inspect worker logs and health as described in [Background work](background-work.md#observe-background-work).
   Treat the result as an observation at that time.
4. After the first scan, confirm ingestion completes and the expected media appears
   in the catalog. Try playback of a known file; test transcoding separately if you
   enable it. A scan finishing only confirms its discovery phase.

These checks establish the paths you exercised. They do not qualify the deployment
for all media, failure recovery or external services. Keep the manifest and private
configuration for later maintenance; [Storage and data](storage-and-data.md#what-must-be-preserved)
identifies the state that needs preservation.

## What's Next

- [Configuration](configuration.md) — environment variables and integrations
- [Background work](background-work.md) — queues, scheduling and worker operation
- [External APIs](external-apis.md) — metadata provider credentials
- [Monitoring](monitoring.md) — health and background jobs
- [CLI Reference](commands/README.md) — console commands and worker deployment
