# Getting Started

Baander is a self-hosted media library server for music, movies, and video. Think of it as your own private Spotify or Netflix — it organizes your collection, enriches it with metadata from external sources like Discogs and MusicBrainz, transcodes video for smooth streaming, and serves it to any device through a modern web interface.

## Prerequisites

- **Docker** and **Docker Compose** — Baander runs entirely in containers
- **Git** — to clone the repository
- **FFmpeg 8.0** — build the static FFmpeg image first: `make build-ffmpeg`
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

This creates the database schema and runs any pending migrations on both the main and test databases.

### 3. Create an admin user

```bash
echo "your-password" | make exec cmd="php bin/console app:user:create admin@example.com Admin --password --role admin"
```

See the [User Management](user-management.md) page and the [CLI Reference](commands/app-user-create.md) for details.

### 4. Configure ports (if needed)

By default Baander listens on port 80 (HTTP) and 443 (HTTPS). If those ports are already in use on your machine, set custom ports in `.env` before starting:

```env
WEB_PORT_HTTP=8080
WEB_PORT_SSL=8443
```

Then start with `make start` and visit `http://localhost:8080`.

### 5. Set up OAuth clients

```bash
make exec cmd="php bin/console app:auth:setup-clients"
```

The command prints two environment variables. Add them to your `.env` file:

```env
AUTH_SPA_CLIENT_ID=<printed-id>
AUTH_ELECTRON_CLIENT_ID=<printed-id>
```

### 6. Create a media library

```bash
make exec cmd="php bin/console app:library:create \"My Music\" /path/to/your/music"
```

Replace `/path/to/your/music` with the absolute path to your media directory on the Docker host. This path must be accessible from inside the container (configured as a volume mount in `docker-compose.yml`).

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
