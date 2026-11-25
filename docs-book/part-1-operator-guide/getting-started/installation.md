# Installation

This guide installs Bånder through the [deployment preset](../../../deploy/preset/README.md):
it builds the production images from a source checkout, writes one private
configuration, and lets `php scripts/deploy-preset.php` start the gateway, the web
server, PostgreSQL, the connection pooler, Redis and the background worker pair.
It then creates the first account and scans a first library. Read
[Understanding Baander](understanding-baander.md) for the component roles and
[Storage and data](storage-and-data.md) before choosing volumes or importing media.

The development stacks are not installations. A worktree preview and the Compose
stack in the repository's `docker-compose.yml` both run with `APP_ENV=dev` and
mount the checkout into the web container; the
[developer guide](../../part-2-developer-guide/getting-started/development-environment.md)
describes them.

This procedure has not yet been run end to end: no disposable installation that
follows this page has been recorded. The earlier
[source-installation check](../../../ROADMAP.md#source-installation-documentation)
exercised the development Compose stack that this page no longer describes, so it
verifies none of the steps below.

## Prerequisites

- A Linux Docker host with cgroup v2 and a rootful Docker daemon on a local Unix
  socket. Rendering refuses a rootless daemon, which cannot apply the negative
  out-of-memory scores of the [kill order](system-requirements.md#kill-order). The
  host needs enough CPU and memory for web, PostgreSQL, Redis, the worker pair and
  the media jobs you run at once; containers carry no resource limits, and the
  media slots bound media work. [System Requirements](system-requirements.md) gives
  the measured cost of each job and the starting points for each host size.
- Docker with Compose and Buildx. The image builds pull base images and the
  project's FFmpeg image from public registries.
- Git, OpenSSL for generating secrets, and host PHP 8.5 with the Swoole extension
  and Composer for the build and deployment tooling.
- A free TCP port on the address the gateway will listen on, and the media files
  you want to scan.

## Command context

Run every command in this guide on the Docker host, from the checkout of the
revision you deploy, as the account that owns the private configuration.
`php scripts/deploy-preset.php` renders, starts, stops and reports the deployment.
The preset names its Compose project after the configuration's `name`, which this
guide sets to `baander` in [step 4](#4-write-the-private-configuration). Define
this function in the shell you use for the installation:

```bash
dc() {
    docker compose --project-name baander "$@"
}
```

If you choose another `name`, put it in place of `baander`. Docker Compose finds a
running service by its project name alone, so `dc` needs no Compose file for
`exec`, `logs` and `ps`. The preset keeps using the Docker daemon it first rendered
against; run `dc` with the same Docker context or `DOCKER_HOST`.

Operator pages run a console command in the web container in one form:

```bash
dc exec web php bin/console app:user:list
```

Pages add `-T`, as in `dc exec -T web php bin/console …`, where a command reads
standard input from a file or its output is captured. A plain `php bin/console …`
example applies only inside an application container, in its application
directory.

Use `dc` for `exec`, `logs` and `ps` only. The preset's `start` and `stop` actions
own the services, their start order and the worker pair. A `dc up` or `dc run`
from the checkout would read the checkout's development `docker-compose.yml`
under this deployment's project name, and `dc down` would remove the services
behind the preset's back.

Make commands select the checkout's development preview, or with
`INSTANCE=persistent` the persistent development instance. Neither selects this
installation. See [Which installation a command targets](understanding-baander.md#which-installation-a-command-targets).

## Installation

Use a fresh checkout and fresh volumes. For an existing installation, follow
[Upgrading](../maintenance/upgrading.md) instead; do not replace its database or
media volumes.

### 1. Install the host tooling

Clone the repository from its Git remote, select the revision to deploy and install
the standalone build and deployment tooling:

```bash
git clone <repository-url> baander
cd baander
git checkout <revision>
composer --working-dir=scripts install --no-dev --no-interaction
```

The tooling runs on the host. None of these steps needs a local `.env`; the
production image creates its own from `.env.example`.

### 2. Build the web frontend

The application image copies the compiled web assets from `public/`, and its build
does not compile them. Build them in a disposable Node container with the
repository's pinned Yarn version, as your own user, so the output stays yours:

```bash
docker run --rm --user "$(id -u):$(id -g)" \
    --env HOME=/tmp --env COREPACK_ENABLE_DOWNLOAD_PROMPT=0 \
    --volume "$PWD:/src" --workdir /src/ui/web \
    node:24.22.2-trixie-slim \
    sh -ec 'corepack yarn install --immutable && corepack yarn build && node scripts/verify-build.mjs'
```

The build type-checks the frontend and writes its assets into `public/`. The last
command checks that the production entry points and every listed asset exist; stop
if it fails.

### 3. Build the images

Build the application, worker and operator images from the same revision, then the
database image. The deployment refuses application, worker and operator images that
declare different revisions:

```bash
revision=$(git rev-parse HEAD)
php scripts/build-application.php --target production --revision "$revision" --tag baander-app:local
php scripts/build-application.php --target worker --revision "$revision" --tag baander-worker:local
php scripts/build-application.php --target dev-operator --revision "$revision" --tag baander-operator:local
docker build --file docker/postgres/Dockerfile --tag baander-database:local docker/
docker pull nginx:stable-alpine
docker pull redis:7-alpine
```

The `dev-operator` target holds the trusted worker controller and the Docker client
on the production application layer. Keep the checkout and `public/` unchanged
between the three application builds, so that all three contain the same code.
The deployment resolves each image reference to an immutable image ID on the
selected daemon; it never builds or pulls an image itself, which is why the gateway
and Redis images are pulled here.

### 4. Write the private configuration

Copy the example for the nearest starting point and capability into a private
directory. The capability decides whether the deployment may re-encode audio and
video; [System Requirements](system-requirements.md) helps you choose. This guide
uses `/srv/baander`; any absolute directory that only the operator account can read
works:

```bash
install -d -m 0700 /srv/baander
install -m 0600 deploy/preset/example-16gb-transcode.json /srv/baander/preset.json
```

Edit `/srv/baander/preset.json`:

```json
{
    "version": 2,
    "name": "baander",
    "origin": "https://media.baander.app",
    "images": {
        "application": "baander-app:local",
        "worker": "baander-worker:local",
        "operator": "baander-operator:local",
        "postgres": "baander-database:local",
        "gateway": "nginx:stable-alpine",
        "redis": "redis:7-alpine"
    }
}
```

The excerpt shows only the keys to change; keep the example's `capability`, `size`,
`listen`, `network` and `resources` unless you mean to change them.

1. **Name.** `name` names the Compose project, the networks and every volume, such
   as `baander-media`. It takes 3 to 41 lowercase letters, digits or hyphens.
2. **Origin and listener.** `origin` is the address your users open. The gateway
   serves plain HTTP on `listen.address` and `listen.port`, by default
   `127.0.0.1:8080`. For an HTTPS origin, run a TLS reverse proxy in front of the
   gateway; a proxy on the same host reaches the loopback listener, and a proxy
   elsewhere must be declared in `listen.frontProxies`. See
   [Gateway and client addresses](../../../deploy/preset/README.md#gateway-and-client-addresses).
3. **Secrets.** Replace every value in `environment` that begins with
   `replace-with-`; rendering and starting refuse any that remain. Generate
   `APP_SECRET`, `STREAM_URL_HMAC_SECRET`, `DB_PASSWORD` and `REDIS_PASSWORD` each
   with `openssl rand -hex 32`, and `AUTH_TOTP_ENCRYPTION_KEY` with
   `openssl rand -base64 32`. The containers refuse to boot when `APP_SECRET`,
   `STREAM_URL_HMAC_SECRET` or `AUTH_TOTP_ENCRYPTION_KEY` is shorter than 32
   bytes, and when the database or Redis password is `baander`, the repository's
   development password.
4. **Mail.** The examples set `MAILER_DSN` to `null://null`, which discards every
   message. Set an SMTP DSN before users need verification or recovery mail.
5. **Network.** The edge network takes `10.231.0.0/28` and `fd00:baa:231::/64` by
   default. If a range is in use, or the daemon cannot create IPv6 networks,
   change `network` as the
   [preset README](../../../deploy/preset/README.md#gateway-and-client-addresses)
   describes.

The preset derives `APP_ENV=prod`, the database and Redis URLs, the media paths,
`TRUSTED_PROXIES` and the deployment profile, and refuses them in `environment`.
Add other variables, such as provider credentials, to `environment`; see
[Configuration](../configuration/README.md).

### 5. Render and start the deployment

Rendering validates the configuration and the images and writes the private
Compose document without starting anything. Start then runs the deployment; status
reports it:

```bash
php scripts/deploy-preset.php render --config /srv/baander/preset.json --state /srv/baander/preset-state
php scripts/deploy-preset.php start --config /srv/baander/preset.json --state /srv/baander/preset-state
php scripts/deploy-preset.php status --state /srv/baander/preset-state
```

`start` creates each missing named volume, starts PostgreSQL and Redis, gives the
database role and the pooler one SCRAM verifier, runs pending schema migrations
through the pooler, creates the OAuth key pair when the secrets volume holds none,
verifies the effective database settings, starts web and the gateway, and launches
the worker pair. It returns once the worker controller reports the pair ready.
`status` then reports `"phase": "running"` and `"workersRunning": true`. If `start` fails, the state directory keeps the phase it
reached; correct the cause and run `start` again. Keep the state directory: every
later `start`, `stop` and `status` needs it.

### 6. Create the sign-in client and the first account

The deployment creates no account, and self-registration starts closed. Create the
first-party client that password and passkey sign-in use, with your origin, then
the first account as a super administrator:

```bash
dc exec web php bin/console app:auth:setup-clients --origin=https://media.baander.app
dc exec web php bin/console app:user:create admin@baander.app Admin --role super-admin
```

Choose a password at the hidden prompt and confirm the administrator role. Only a
super administrator can change server settings and other administrators from the
web app, and self-registration stays closed until a super administrator opens it
(see [Registration and address changes](../configuration/account-security.md#registration-and-address-changes)).
See [User management](../administration/user-management.md) and
[app:user:create](../commands/app-user-create.md) for password rules and scripted
creation.

### 7. Add media and scan a library

Web and the workers mount the media volume, `baander-media`, read-only at
`/storage/media`. Copy media into it with a disposable container of the
application image, which gives the files to the image's runtime user:

```bash
docker run --rm --network none --user 0:0 --entrypoint sh \
    --mount type=volume,src=baander-media,dst=/storage/media \
    --mount type=bind,src=/path/to/music,dst=/import,readonly \
    baander-app:local \
    -ec 'mkdir -p /storage/media/music && cp -R /import/. /storage/media/music/ && chown -R www-data:www-data /storage/media/music'
```

Keep the originals intact. Then create a music library with its media type and an
explicit slug, and scan it:

```bash
dc exec web php bin/console app:library:create "My Music" /storage/media/music music --slug my-music
dc exec web php bin/console app:library:scan my-music
```

The console scan discovers files in that process, records its work in the job
monitor and queues directories for ingestion. The workers must finish ingestion
before the media appears in the catalog; a completed scan alone does not establish
that background processing works. See [Library management](../media/library-management.md)
for library access and scanning.

## Check the installation

Check the web and background paths separately before giving users access:

1. Open the origin and sign in as the administrator. This checks the browser,
   proxy and authentication path for that account.
2. Run `dc exec -T web php bin/console app:health:check` and inspect web readiness
   using [Monitoring](../operations/README.md). The web readiness check tests
   PostgreSQL and Redis; it does not establish worker readiness or that external
   integrations work.
3. Run `php scripts/deploy-preset.php status --state /srv/baander/preset-state` and
   observe background work as described in
   [Background work](../workers/background-work.md#observe-background-work). Treat
   the result as an observation at that time.
4. After the first scan, confirm that ingestion completes and the expected media
   appears in the catalog. Play a known file; test transcoding separately if the
   capability allows it. A finished scan confirms only its discovery phase.

These checks establish the paths you exercised. They do not qualify the deployment
for all media, failure recovery or external services. Keep the configuration file
and the state directory private, and include them in your backups;
[Storage and data](storage-and-data.md#what-must-be-preserved) identifies the state
that needs preservation.

## What's Next

- [Configuration](../configuration/README.md) — environment variables and integrations
- [Background work](../workers/background-work.md) — queues, scheduling and worker operation
- [External APIs](../media/external-apis.md) — metadata provider credentials
- [Monitoring](../operations/README.md) — health and background jobs
- [CLI Reference](../commands/README.md) — console commands and worker deployment
