# Bånder

Self-hosted media library server for music, movies, and video. Organizes your collection, enriches it with metadata from external sources, and streams it anywhere.

## Features

**Media management** — Catalog and browse music, movies, and videos with structured metadata. Automatic library scanning detects new files via inotify.

**Metadata enrichment** — Pulls metadata from Discogs, Last.fm, Spotify, MusicBrainz, and TasteDive to fill in artist info, album art, genres, and more.

**Search** — Full-text search powered by PGroonga with cursor-based pagination.

**Streaming** — Media streaming through an Nginx reverse proxy.

**Authentication** — OAuth 2.0 authorization server with passkey (WebAuthn) login and TOTP two-factor authentication.

**Notifications** — Web push notifications and webhook delivery for events like new library additions.

**Audio analysis** — Essentia and FFmpeg integration for audio feature extraction.

## Project Structure

The codebase follows Domain-Driven Design with bounded contexts. Each context is organized into four layers:

```
src/<Context>/
├── Domain/              # Business rules, entities, value objects
├── Application/         # Use cases, command/query handlers
├── Infrastructure/      # Database, external services, adapters
└── Interface/           # API controllers, request/response handling
```

| Context | Responsibility |
|---------|---------------|
| Auth | OAuth 2.0 server, passkeys, TOTP |
| Catalog | Artists, albums, songs, movies, videos, genres |
| Library | Media library management and file scanning |
| Media | File handling, streaming, image storage |
| Metadata | External API enrichment |
| Playlist | Playlist management |
| Recommendation | Music recommendations |
| Activity | Listen history tracking |
| Notification | Notifications and preferences |
| Filesystem | File operations, MIME detection, inotify watching |
| Lyrics | Lyrics handling |
| Shared | Cross-cutting: domain models, caching, logging, API utilities |

## Tech Stack

- PHP 8.5+ with Symfony 8.0
- Swoole (async runtime with hot module replacement)
- PostgreSQL 18 with PGroonga full-text search
- Redis (caching, message queue)
- Doctrine ORM 3.6
- Nginx reverse proxy
- FFmpeg / Essentia for audio analysis

## Setup

Requires Docker and Docker Compose.

```bash
cp .env.example .env
make build
make start
make composer-install
make migrate
```

Then visit `http://localhost`.

## Message delivery and upgrades

Queued commands and job retry data use the `baander.message` JSON format, version 1.
The codec lists supported message types and fields explicitly. Redis and Swoole use
this same contract; message payloads do not depend on Symfony object serialization.
Retry metadata retains completed handler names so a retry skips handlers that
already succeeded. External delivery still requires consumer idempotency.

Before upgrading an instance that has older queued messages, stop producers and
use the previous release to drain Redis and Swoole work. Resolve or export failed
messages and back up the Redis streams and job history. Upgrade producers and
workers together. PHP-serialized envelopes and legacy `__class` job payloads are
rejected; archived legacy jobs cannot be retried through the new codec. Do not
remove pending messages to make an upgrade pass.

Run the strict unit suite with `bash scripts/test-unit-container.sh`. Run real
Redis delivery, retry, failure-stream, and PostgreSQL lease tests with
`bash scripts/test-messaging-container.sh`. Both scripts require installed Composer
dependencies and Docker. The messaging script creates disposable services on its
own network; it does not use the application's database or Redis instance.

## License

Proprietary.
