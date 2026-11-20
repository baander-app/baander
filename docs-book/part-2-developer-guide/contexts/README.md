# Bounded Contexts

Use this index to find the context that owns the behavior you want to change.
Each context has its own domain model, rules and language; its page explains
responsibilities and links to models, ports, endpoints and related contexts.
The Shared kernel supplies common building blocks. For the four-layer structure,
start with the [Architecture Overview](../architecture/overview.md).

| Page | Description |
|------|-------------|
| [Activity](activity.md) | Play history, love/skip tracking, recently played items, and play analytics |
| [Auth](auth.md) | OAuth 2.0 server, passkeys, TOTP, and DPoP |
| [Catalog](catalog.md) | Artists, albums, songs, movies, videos, and genres |
| [Command](command.md) | CLI utilities (OpenAPI export, documentation generation, dev setup, OAuth key generation) |
| [Discovery](discovery.md) | LAN server discovery and device pairing |
| [Favorites](favorites.md) | User favorites (songs, artists, albums) |
| [Filesystem](filesystem.md) | Local/read-only filesystem abstractions, MIME detection, and inotify file watching |
| [Library](library.md) | Media library management and file scanning |
| [Lyrics](lyrics.md) | Lyrics storage and retrieval |
| [Media](media.md) | Image storage, media streaming, playback delivery negotiation, and BlurHash |
| [Metadata](metadata.md) | Audio tag parsing and external API enrichment (Discogs, Last.fm, Spotify, MusicBrainz, TasteDive, CoverArtArchive, TMDB) |
| [Notification](notification.md) | Push, email, webhook, and in-app notifications |
| [Party](party.md) | Synchronized watch-party playback |
| [Playlist](playlist.md) | Manual playlists, collaborators, and stored smart playlist rules |
| [QoL](qol.md) | Stream governance and quality-of-life allocation |
| [Radio](radio.md) | Internet radio, country subscriptions, and station starring |
| [Recommendation](recommendation.md) | Music recommendation algorithms |
| [Scheduler](scheduler.md) | Cron-like scheduled job execution |
| [Session](session.md) | Listening sessions and registered devices |
| [Transcode](transcode.md) | CMAF video transcoding via FFmpeg and Swoole |
| [User Preference](user-preference.md) | Accent color, sidebar, audio/layout/player preferences, EQ profiles, theme mood, and user settings |
| [Shared](shared.md) | Cross-cutting kernel: UUID, Swoole, caching, WebSocket, and settings |

## Auth and Catalog references

The Auth and Catalog overviews introduce the mechanisms. Use these references
when you need their implementation detail.

| Reference | What to find |
| --- | --- |
| [Auth inventory](auth-inventory.md) | Models, ports, events and endpoints |
| [Account security](auth-account-security.md) | Passwords, account verification, passkeys and second factors |
| [Auth tokens](auth-tokens.md) | Issuance, proof binding, rotation and revocation |
| [OAuth server](auth-oauth-server.md) | Authorization and grant processing |
| [OAuth client authentication](auth-client-authentication.md) | Client credentials and key verification |
| [Catalog inventory](catalog-inventory.md) | Models, ports, events and endpoints |
| [Catalog matching](catalog-matching.md) | Candidate selection, applying matches and background processing |
| [Catalog imports](catalog-imports.md) | Import, rescan and media identity behavior |
