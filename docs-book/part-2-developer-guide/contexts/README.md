# Bounded Contexts

Each bounded context owns one business capability, with its own domain model, rules and language. Each page below covers one context, or the Shared kernel that the contexts build on: its domain models, ports, infrastructure, API endpoints and relationships with other contexts. For the four-layer structure and how contexts communicate, start with the [Architecture Overview](../architecture.md).

| Page | Description |
|------|-------------|
| [Activity](activity.md) | Listen history and favorites tracking |
| [Auth](auth.md) | OAuth 2.0 server, passkeys, TOTP, and DPoP |
| [Catalog](catalog.md) | Artists, albums, songs, movies, videos, and genres |
| [Command](command.md) | CLI utilities (OpenAPI export) |
| [Discovery](discovery.md) | LAN server discovery and device pairing |
| [Favorites](favorites.md) | User favorites (songs, artists, albums) |
| [Filesystem](filesystem.md) | MIME detection and inotify file watching |
| [Library](library.md) | Media library management and file scanning |
| [Lyrics](lyrics.md) | Lyrics storage and retrieval |
| [Media](media.md) | Image storage, streaming, and BlurHash |
| [Metadata](metadata.md) | External API enrichment (Discogs, Last.fm, Spotify, MusicBrainz) |
| [Notification](notification.md) | Push, email, webhook, and in-app notifications |
| [Party](party.md) | Synchronized watch-party playback |
| [Playlist](playlist.md) | Manual and smart playlist management |
| [QoL](qol.md) | Stream governance and quality-of-life allocation |
| [Radio](radio.md) | Internet radio, country subscriptions, and station starring |
| [Recommendation](recommendation.md) | Music recommendation algorithms |
| [Scheduler](scheduler.md) | Cron-like scheduled job execution |
| [Session](session.md) | Listening sessions and registered devices |
| [Transcode](transcode.md) | CMAF video transcoding via FFmpeg and Swoole |
| [User Preference](user-preference.md) | Accent color, sidebar, audio/layout/player preferences, EQ profiles, theme mood, and user settings |
| [Shared](shared.md) | Cross-cutting kernel: UUID, Swoole, caching, WebSocket, and settings |
