# Getting Started

This guide prepares a new Bånder instance from source, creates an administrator and
scans a first library. The current Compose stack is development-oriented: it mounts
the checkout into the web container and uses a local TLS certificate. Background
processing needs a separate worker deployment.

A disposable installation verified frontend loading, administrator login, worker
startup and a sample music scan through ingestion and browser catalog display.
Sample music playback failed with HTTP 500 because streaming rejected an absolute
media path; this procedure does not yet establish working playback. See the
[bootstrap checkpoint](../../ROADMAP.md#source-installation-documentation) for
evidence, environment adaptations and verification limits.

## Prerequisites

- Git, Docker with Compose and Buildx, and OpenSSL for generating secrets.
- A Linux Docker host with cgroup v2, a local Docker Unix socket and enough CPU and
  memory for web, PostgreSQL, Redis and the worker pair. The worker guide's example
  reserves 2944 MiB for control and 4800 MiB for execution; admission also requires
  1 GiB of host memory beyond those ceilings and disjoint CPU sets.
- Host PHP 8.5 with `posix` and `pdo_pgsql` for the worker controller. Composer
  dependencies are installed into the checkout below. The controller runs on the
  trusted host; the application containers receive no Docker socket.
- Media files that you can copy into a shared named Docker volume. Worker admission
  does not accept host-directory bind mounts for media.

FFmpeg, Node and Corepack are supplied by the app image. The web frontend selects
its Yarn version from the repository's `packageManager` field.

Read [Worker deployment](commands/README.md#worker-deployment) before provisioning
storage or choosing host resources. Its version-4 procedure is authoritative for
the worker manifest, credentials, volume ownership and admission checks.

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
```

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
command below so it reads the private settings explicitly:

```bash
export HOST_UID="$(id -u)"
export HOST_GID="$(id -g)"

dc() {
    docker compose --env-file .env.dev.local -f docker-compose.yml "$@"
}
```

Run it in the same shell as the remaining commands. Remove conflicting exported
variables before using it; shell values override Compose's environment file. The
Makefile shortcuts do not load this additional file.

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
            - ./:/var/www/html:cached
            - ./docker/dev/php.ini:/usr/local/etc/php/php.ini:ro
            - ./docker/dev/swoole.ini:/usr/local/etc/php/conf.d/docker-php-ext-swoole.ini:ro
            - baander-source:/media:ro
            - baander-conversions:/storage/conversions
            - baander-storage:/var/www/html/storage
            - baander-transcode-state:/var/www/html/var/transcode_state
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

```bash
dc config --quiet
dc build
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
dc run --rm --no-deps -w /var/www/html/ui/web app corepack yarn install --immutable
dc run --rm --no-deps -w /var/www/html/ui/web app corepack yarn build
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
version-4 deployment from the host. Use the network and media volumes prepared above;
the host and worker database URLs must reach the same migrated database. Supply the
same application secret and Redis credentials through the worker credentials file.
Worker runtime uses `APP_ENV=prod` and does not load the web's `.env.dev.local`.

The admitted worker pair owns background consumption, scheduling, outbox relay and
media execution. Compose starts none of these. Check worker logs and operational
health as described in that guide; controller `status` reports
`readiness: "not_checked"` and is not a readiness test.

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

## What's Next

- [Configuration](configuration.md) — environment variables and integrations
- [External APIs](external-apis.md) — metadata provider credentials
- [Monitoring](monitoring.md) — health and background jobs
- [CLI Reference](commands/README.md) — console commands and worker deployment
