# Getting Started

Baander is a self-hosted media library server for music, movies, and video. Think of it as your own private Spotify or Netflix — it organizes your collection, enriches it with metadata from external sources like Discogs and MusicBrainz, transcodes video for smooth streaming, and serves it to any device through a modern web interface.

## Prerequisites

- **Docker** and **Docker Compose** — Baander runs entirely in containers
- **Git** — to clone the repository
- **FFmpeg** — bundled in the app container image (installed via apt); no separate build step
- A directory with your media files (music, movies, or video) accessible from the Docker host

## Installation

```bash
git clone <repository-url> baander
cd baander

cp .env.example .env
make build
make start
```

After the containers start, set a Redis password in `.env`:

```env
REDIS_PASSWORD=a_secure_password
```

Then restart: `make restart`.

## Initial Setup

After the containers are running, complete these steps in order.

### 1. Install PHP dependencies

```bash
make composer-install
```

### 2. Run database migrations

```bash
make migrate
```

This initializes both the main and test databases with `Version20261017000000`,
then applies the forward migrations through `Version20261018130000`. The initial
migration creates the 80-table schema and two daily schedules: OAuth code purging
at 03:30 UTC and recommendation generation at 04:00 UTC. The forward migrations
add playlist persistence versions, video/audio execution authority, durable
control receipts, playback-owner scoping and paired worker deployment journals. Running migrations again leaves no
pending work.

Each target must be empty before the initial migration; preinstalled extensions
and an empty Doctrine migration metadata table are allowed. Existing user objects,
even an empty table, cause initialization to stop before application schema changes.
A database already initialized with `Version20261017000000` applies the forward
migrations normally, preserving its data.

For the main database alone, use `make migrate-no-test`. For an existing database
with an older migration history, follow the [manual reset procedure](upgrading.md#manual-reset-of-an-existing-database).

The web container runs `app:serve`; it handles requests, streams files and keeps
client connections. Background work requires a separately admitted `app:worker`
control and execution pair. After migrations, follow [Worker deployment](commands/README.md#worker-deployment)
to build the worker image, provision shared named media volumes and a private IPC
volume owned by that image's runtime UID/GID with mode `0700`, select disjoint
control/execution CPU sets, and create/start the deployment. The development Compose file starts only web and dependencies;
`make start` alone does not start consumers, scheduling or media encoding.

### 3. Create an admin user

```bash
echo "your-password" | make exec cmd="php bin/console app:user:create admin@baander.app Admin --password --role admin --force"
```

The admin role needs confirmation; `--force` gives it when the password comes from a pipe. See the [User Management](user-management.md) page and the [CLI Reference](commands/app-user-create.md) for details.

### 4. Configure ports (if needed)

By default Baander listens on port 80 (HTTP) and 443 (HTTPS). If those ports are already in use on your machine, set custom ports in `.env` before starting:

```env
WEB_PORT_HTTP=8080
WEB_PORT_SSL=8443
```

Then start with `make start` and visit `http://localhost:8080`.

### 5. Set up the OAuth client

```bash
make exec cmd="php bin/console app:auth:setup-clients"
```

The command prints one environment variable. Make sure your `.env` file contains it:

```env
AUTH_SPA_CLIENT_ID=<printed-id>
```

### 6. Create a media library

```bash
make exec cmd="php bin/console app:library:create \"My Music\" /path/to/your/music"
```

Replace `/path/to/your/music` with the absolute path inside both web and worker, for example `/media/library/music` below `MEDIA_STORAGE_PATH=/media/library` in a shared source volume. Use identical paths in both roles; worker reads sources and writes derived media through the admitted volumes. Keep web access needed for authorized file deletion. See [Worker deployment](commands/README.md#worker-deployment) for the storage declarations.

### 7. Scan your library

```bash
make exec cmd="php bin/console app:library:scan my-music"
```

Replace `my-music` with the slug of the library you created. The scan discovers media files, extracts metadata, and populates the database.

### 8. Open Baander

Visit `http://localhost` (or whichever port you configured) in your browser. Log in with the admin account you created.

After logging in you'll see the main dashboard with your media library. From there you can browse albums, artists, movies, and videos. The sidebar provides navigation to playlists, search, and settings. Audio plays inline in a persistent player bar at the bottom of the screen; video opens in a built-in player with quality selection.

## What's Next

- [Configuration](configuration.md) — customize environment variables, ports, and integrations
- [Security](security.md) — harden your installation and set up secret rotation
- [External APIs](external-apis.md) — enable metadata enrichment from Discogs, Last.fm, and MusicBrainz
- [CLI Reference](commands/README.md) — all available console commands
