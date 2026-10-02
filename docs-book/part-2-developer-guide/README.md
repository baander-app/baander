# Part II — Developer's Guide

Understand the architecture, write code following project conventions, and contribute to Baander.

## Architecture

| Page | Description |
|------|-------------|
| [Architecture Overview](architecture.md) | DDD bounded contexts, four-layer structure, and shared kernel |
| [Shared Kernel](shared-kernel.md) | UUID v7, cursor pagination, search, Swoole async, caching, SSE/WebSocket |
| [Real-Time Patterns](real-time-patterns.md) | WebSocket connection registry, SSE via Redis Pub/Sub, party sync |
| [API Reference](api-reference.md) | OpenAPI spec, REST patterns, and authentication flows |

## Development

| Page | Description |
|------|-------------|
| [Development Environment](development-environment.md) | Docker setup, Makefile commands, IDE config, and Xdebug |
| [Coding Conventions](coding-conventions.md) | Domain models, value objects, repositories, CQRS, ports, and anti-corruption |
| [CQRS and Messaging](cqrs-and-messaging.md) | Commands, handlers, domain events, and async processing |
| [Search](search.md) | PGroonga full-text search, making a context searchable |
| [Testing](testing.md) | PHPUnit suites, conventions, and code examples |
| [Frontend Development](frontend-development.md) | React + TypeScript + Vite + styled-components |

## Bounded Contexts

| Page | Description |
|------|-------------|
| [Activity](contexts/activity.md) | Listen history and favorites tracking |
| [Auth](contexts/auth.md) | OAuth 2.0 server, passkeys, TOTP, and DPoP |
| [Catalog](contexts/catalog.md) | Artists, albums, songs, movies, videos, and genres |
| [Command](contexts/command.md) | CLI utilities (OpenAPI export) |
| [Discovery](contexts/discovery.md) | LAN server discovery and device pairing |
| [Favorites](contexts/favorites.md) | User favorites (songs, artists, albums) |
| [Filesystem](contexts/filesystem.md) | MIME detection and inotify file watching |
| [Library](contexts/library.md) | Media library management and file scanning |
| [Lyrics](contexts/lyrics.md) | Lyrics storage and retrieval |
| [Media](contexts/media.md) | Image storage, streaming, and BlurHash |
| [Metadata](contexts/metadata.md) | External API enrichment (Discogs, Last.fm, Spotify, MusicBrainz) |
| [Notification](contexts/notification.md) | Push, email, webhook, and in-app notifications |
| [Party](contexts/party.md) | Synchronized watch-party playback |
| [Playlist](contexts/playlist.md) | Manual and smart playlist management |
| [QoL](contexts/qol.md) | Stream governance and quality-of-life allocation |
| [Radio](contexts/radio.md) | Internet radio, country subscriptions, and station starring |
| [Recommendation](contexts/recommendation.md) | Music recommendation algorithms |
| [Scheduler](contexts/scheduler.md) | Cron-like scheduled job execution |
| [Session](contexts/session.md) | Listening sessions and registered devices |
| [Transcode](contexts/transcode.md) | CMAF video transcoding via FFmpeg and Swoole |
| [User Preference](contexts/user-preference.md) | Accent color, sidebar, audio/layout/player preferences, EQ profiles, and theme mood |
| [Shared](contexts/shared.md) | Cross-cutting kernel: UUID, Swoole, caching, SSE, WebSocket |

## Contributing

| Page | Description |
|------|-------------|
| [Adding a New Feature](adding-a-feature.md) | Step-by-step walkthrough for adding an API endpoint |
| [Contributing](contributing.md) | Branch naming, commit style, PR process, and documentation maintenance |
| [Glossary](glossary.md) | DDD, infrastructure, and data type terminology |

## See Also

- [Part I — Operator's Guide](../part-1-operator-guide/README.md) — Prerequisite reading for developers (you need to run the app before you can develop on it).
- [AGENTS.md](../../AGENTS.md) — Agent instructions and pointers to canonical rules and repository skills.
