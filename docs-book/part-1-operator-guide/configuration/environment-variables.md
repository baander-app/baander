# Environment Variables

Environment variables configure application URLs, PostgreSQL and Redis connections, Messenger transports, mail and push delivery, external metadata providers, media paths, and scanner safety limits.

## Application

The application name is fixed as Bånder. Environment variables cannot change the
name shown in emails, passkey prompts or TOTP enrollment.

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_ENV` | `dev` | Symfony environment: `dev`, `prod`, or `test`. Controls debugging, caching, and error handling. |
| `APP_SECRET` | — | One cryptographic secret for Symfony security components and encrypted webhook signing secrets. Generate a unique value for each deployment; use the [offline rotation procedure](../maintenance/security.md#rotating-app_secret) to change it. |
| `APP_URL` | `https://localhost` | Canonical URL of the application. Used for CORS configuration and URL generation. |
| `APP_DOMAIN` | `localhost` | Domain name displayed or used in API responses. |

## Database

| Variable | Default | Description |
|----------|---------|-------------|
| `DATABASE_URL` | `postgresql://baander:baander@pooler:6432/baander?serverVersion=18&charset=utf8` | Full Doctrine DBAL connection URL for the pooler's application alias. Format: `postgresql://user:password@host:port/dbname?serverVersion=18&charset=utf8`. The `serverVersion` parameter should match your PostgreSQL version. |
| `DATABASE_CONTROL_URL` | `postgresql://baander_control:baander@pooler:6432/baander_control?serverVersion=18&charset=utf8` | Connection URL for the pooler's control alias, used by worker leases, media authority checks and the scheduler. Its user and database names append `_control`; its password is the database password. See [Connection pooler](deployment-profiles.md#connection-pooler). |

The test database uses `baander_test` as the database name — configured in `.env.test`.

## Redis

| Variable | Default | Description |
|----------|---------|-------------|
| `REDIS_PASSWORD` | — | Password for the Redis instance. Optional in dev, **required in production**. Used by cache pools, messenger transport, and session storage. |
| `REDIS_URL` | `redis://default:%env(REDIS_PASSWORD)%@redis:6379` | Redis endpoint URL. Worker deployments require it to be pathless and queryless because the scheduler appends its stream and group. The password is interpolated from `REDIS_PASSWORD`. |

Redis is used for:
- **Cache** — tag-aware caching for API responses, OAuth tokens, and user sessions
- **Messenger** — async job transport (scan jobs, notification delivery)
- **Rate limiting** — limiter state for API requests, for login, passkey, registration, password reset and token refresh attempts, and for the OAuth authorization server endpoints
- **DPoP stores** — the single-use nonces of the token endpoints and the proof identifiers seen on API requests (replay protection)

Redis connections from the application pool and Doctrine cache pools have
one-second connect and read timeouts. A request can make several Redis calls, so
these are per-connection bounds rather than a one-second total request deadline.
Rate limiters use the pooled client directly, with atomic state in separate
namespaces; they do not use cache pools. Unavailable rate-limit state answers
`503` with `Retry-After: 1`; unavailable DPoP state answers `503` with
`Retry-After: 5`. Both carry `Cache-Control: no-store`, and the first rejecting
check determines the response. Clients keep their sessions. See
[Sessions and a Redis outage](../maintenance/security.md#sessions-logout-and-a-redis-outage) for
the narrow logout/revocation exceptions.

## Messenger

| Variable | Default | Description |
|----------|---------|-------------|
| `MESSENGER_TRANSPORT_DSN` | `redis://default:%env(REDIS_PASSWORD)%@redis:6379/messages` | Redis URL for the `async` transport. Worker deployments accept no path, `/messages` or `/messages/baander`. |
| `MESSENGER_CONSUMER_NAME` | `${HOSTNAME:-worker}` | Standalone consumer fallback. The admitted worker replaces it with an identity derived from the deployment, boot, worker role and launch generation. |

Worker deployments accept a single `redis://` or `rediss://` endpoint in each URL,
including percent-encoded credentials and bracketed IPv6 hosts. A host is required;
percent escapes in hosts and port zero are rejected. URLs cannot contain
fragments, ASCII whitespace or control characters, commas, backslashes, malformed
percent escapes or multiple unescaped `@` separators. `REDIS_URL` must have no path or
query. `MESSENGER_TRANSPORT_DSN` may carry unique scalar query options:
`stream=messages`, `group=baander`, non-negative integer `dbindex` and
`retry_interval`, or finite non-negative decimal `timeout` and `read_timeout`.
Integer values use `0` or digits without leading zeroes; decimal values may add a
fraction to that form. Unknown options, duplicates and nested option names are
rejected. Consumer overrides are rejected, including a consumer path segment.

Credential admission rejects invalid URLs before Docker creation. The supervisor
checks again before launching children; diagnostics identify the setting without
printing its credential-bearing value. These checks preserve the configured
streams, group and generated identity. They do not test endpoint connectivity.

The validated workload catalog in `config/packages/messenger.php` supplies fifteen
default production routes and two explicit-only workloads: lyrics fetch and
webhook delivery. Container compilation checks routes, handlers, codecs, JSON
serialization and bounded retry policies; producer dispatch checks the selected
senders. Execution runs ordinary consumers for `async` and a separate consumer for
`scheduler`. See [Background work](../workers/background-work.md) for how queues, the outbox
and scheduled work reach those consumers. Resource classes
`io`, `cpu` and `coordination` describe work and do not change worker reservations. The `test` environment
configures both receivers as `in-memory://` in that file; changing a production
worker DSN to `in-memory://` is rejected.

Both `async` and `scheduler` configure `max_retries: 3`, `delay: 1000` milliseconds,
`multiplier: 2` and `max_delay: 60000` milliseconds. Symfony recoverable exceptions
can bypass the retry count and supply their own retry delay. These values bound
the configured retry policy; they do not impose an unconditional execution cap.


## Web Push (VAPID)

| Variable | Default | Description |
|----------|---------|-------------|
| `VAPID_PUBLIC_KEY` | — | VAPID public key for browser push notifications. Generate with `php bin/console app:generate-vapid-keys`. |
| `VAPID_PRIVATE_KEY` | — | VAPID private key. Keep this secret. |

## Mail

| Variable | Default | Description |
|----------|---------|-------------|
| `MAILER_DSN` | `smtp://localhost:1025` | Mailer transport DSN. Required for email features (password reset, email verification, notifications). Use your provider's SMTP URL in production. |
| `MAIL_FROM_ADDRESS` | `noreply@localhost` | From address for outgoing email. |

`config/packages/mailer.yaml` builds the `From` header with the fixed name Bånder and the address from `MAIL_FROM_ADDRESS`. Mail servers often reject a sender address whose domain they cannot verify, so use an address at a domain your SMTP provider is allowed to send for. The test environment replaces `MAILER_DSN` with `null://null`, so tests never send real mail.

## External APIs

API keys are optional. MusicBrainz and LRCLIB lookups work without them; other
providers need credentials in the process that makes the request.

| Variable | Default | Description |
|----------|---------|-------------|
| `DISCOGS_TOKEN` | — | Discogs API personal access token. The admitted worker recipe cannot currently provision it for queued enrichment; see [External APIs](../media/external-apis.md#configuration). |
| `LASTFM_API_KEY` | — | Last.fm API key for web artist search and browse information; stored metadata sync does not use it. |
| `LASTFM_API_SECRET` | — | Listed in `.env.example`, but not consumed by the current Last.fm adapter. |
| `SPOTIFY_CLIENT_ID` | — | Adapter credential; no current application workflow calls Spotify. |
| `SPOTIFY_CLIENT_SECRET` | — | Companion adapter secret; it does not enable a current workflow. |
| `TASTE_DIVE_API_KEY` | — | Adapter credential; no current application workflow calls TasteDive. |
| `MUSICBRAINZ_VERSION` | `1.0.0` | Listed in `.env.example`, but not consumed by the current MusicBrainz adapter. |
| `MUSICBRAINZ_CONTACT` | `noreply@localhost` | Listed in `.env.example`, but not consumed by the current MusicBrainz adapter. |

The MusicBrainz adapter sends a fixed application user agent. Changing the two
MusicBrainz environment values above does not change it. See
[External APIs](../media/external-apis.md) for provider behavior and limits.


## Library Scanner Security

Limits applied when scanning media libraries, to prevent path traversal, oversized files, and metadata-based attacks. All variables use the `SCANNER_` prefix.

| Variable | Default | Description |
|----------|---------|-------------|
| `SCANNER_ALLOWED_PATHS` | `/home,/media,/mnt,/Users,/Volumes` | Comma-separated roots the scanner may read. Paths outside these are rejected. |
| `SCANNER_MAX_AUDIO_SIZE_MB` | `500` | Maximum audio file size, in MB. |
| `SCANNER_MAX_VIDEO_SIZE_MB` | `5000` | Maximum video file size, in MB. |
| `SCANNER_MAX_LYRICS_SIZE_MB` | `1` | Maximum lyrics file size, in MB. |
| `SCANNER_MAX_IMAGE_SIZE_MB` | `10` | Maximum image file size, in MB. |
| `SCANNER_MAX_SUBTITLE_SIZE_MB` | `1` | Maximum subtitle file size, in MB. |
| `SCANNER_MAX_TOTAL_FILES` | `100000` | Maximum number of files a single scan processes. |
| `SCANNER_MAX_DIRECTORY_DEPTH` | `20` | Maximum directory recursion depth. |
| `SCANNER_FILE_TIMEOUT` | `30` | Per-file processing timeout, in seconds. |
| `SCANNER_DIR_TIMEOUT` | `300` | Per-directory processing timeout, in seconds. |
| `SCANNER_VALIDATE_MAGIC_BYTES` | `true` | Verify file magic bytes match the claimed MIME type. |
| `SCANNER_ALLOW_MIME_MISMATCH` | `false` | If true, accept files whose MIME type differs from their extension. |
| `SCANNER_SANITIZE_METADATA` | `true` | Neutralize unsafe metadata fields during import. |
| `SCANNER_MAX_TITLE_LENGTH` | `255` | Maximum title length retained from metadata. |
| `SCANNER_MAX_ARTIST_LENGTH` | `255` | Maximum artist name length. |
| `SCANNER_MAX_ALBUM_LENGTH` | `255` | Maximum album name length. |
| `SCANNER_MAX_GENRE_LENGTH` | `100` | Maximum genre length. |
| `SCANNER_MAX_COMMENT_LENGTH` | `1000` | Maximum comment length. |
| `SCANNER_MAX_LYRICS_LENGTH` | `50000` | Maximum lyrics text length. |

## Local Network Discovery

| Variable | Default | Description |
|----------|---------|-------------|
| `DISCOVERY_ENABLED` | `true` | Enable LAN server discovery, so clients find the server without a manual URL. |
| `DISCOVERY_PORT` | `41234` | UDP port used for discovery broadcasts. |
| `DISCOVERY_SERVER_NAME` | `Bånder` | Server name advertised to clients. |

## Media Storage & Tools

| Variable | Default | Description |
|----------|---------|-------------|
| `MEDIA_STORAGE_PATH` | `/storage/media` | Root directory for original media files. |
| `CONVERT_STORAGE_PATH` | `/storage/conversions` | Root directory for transcoded/converted output. |
| `FFMPEG_PATH` | `/usr/bin/ffmpeg` | Path to the FFmpeg binary; the application image installs the project's own FFmpeg build there. |
| `FFPROBE_PATH` | `/usr/bin/ffprobe` | Path to the FFprobe binary. |

## Stream Authentication

| Variable | Default | Description |
|----------|---------|-------------|
| `STREAM_URL_HMAC_SECRET` | `change-me-...` | Secret used to HMAC-sign streaming URLs. **Set a random 64-character value in production.** |

## HTTP

API routes accept cross-origin requests only from the origin in `APP_URL`. Four paths allow any origin: `/.well-known/`, which serves the public JWKS keys and the OAuth authorization server metadata; `POST /api/oauth/token` and `POST /api/oauth/device/authorize`, which third-party and device clients call with a DPoP proof rather than cookies; and `/api/discovery/`, whose endpoints still require authentication. The authorization endpoint `/api/oauth/authorize`, token revocation, and device verification and approval follow the `APP_URL` rule, because only the web app's consent and device pages call them for the signed-in user. Third-party clients never call `/api/oauth/authorize` themselves: they send the user's browser to the consent page. The policy is set in `config/packages/nelmio_cors.yaml`; no environment variable changes it.

## Job Monitoring

| Variable | Default | Description |
|----------|---------|-------------|
| `JOB_MONITOR_MAX_PAYLOAD_SIZE` | `20000000` | Maximum payload size, in bytes, stored per job monitor entry. |

