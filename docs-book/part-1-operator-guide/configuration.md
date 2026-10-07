# Configuration

All configuration in Baander is done through environment variables. They're defined in `.env` and consumed by Symfony's config files. Override them per-environment in `.env.test`, `.env.prod`, or in your deployment setup.

## Application

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_ENV` | `dev` | Symfony environment: `dev`, `prod`, or `test`. Controls debugging, caching, and error handling. |
| `APP_SECRET` | — | One cryptographic secret for Symfony security components and encrypted webhook signing secrets. Generate a unique value for each deployment; use the [offline rotation procedure](security.md#rotating-app_secret) to change it. |
| `APP_URL` | `https://localhost` | Canonical URL of the application. Used for CORS configuration and URL generation. |
| `APP_DOMAIN` | `localhost` | Domain name displayed or used in API responses. |
| `APP_NAME` | `Bånder` | Human-readable application name. Used in notification emails and TOTP issuer display. |

## Database

| Variable | Default | Description |
|----------|---------|-------------|
| `DATABASE_URL` | `postgresql://baander:baander@database:5432/baander?serverVersion=18&charset=utf8` | Full Doctrine DBAL connection URL. Format: `postgresql://user:password@host:port/dbname?serverVersion=18&charset=utf8`. The `serverVersion` parameter should match your PostgreSQL version. |

The test database uses `baander_test` as the database name — configured in `.env.test`.

## Redis

| Variable | Default | Description |
|----------|---------|-------------|
| `REDIS_PASSWORD` | — | Password for the Redis instance. Optional in dev, **required in production**. Used by cache pools, messenger transport, and session storage. |
| `REDIS_URL` | `redis://default:%env(REDIS_PASSWORD)%@redis:6379` | Full Redis DSN. The password is interpolated from `REDIS_PASSWORD`. |

Redis is used for:
- **Cache** — tag-aware caching for API responses, OAuth tokens, and user sessions
- **Messenger** — async job transport (scan jobs, notification delivery)
- **Rate limiting** — limiter state for API requests and for login, passkey, registration, password reset and token refresh attempts

## Messenger

| Variable | Default | Description |
|----------|---------|-------------|
| `MESSENGER_TRANSPORT_DSN` | `redis://default:%env(REDIS_PASSWORD)%@redis:6379/messages` | DSN for the message queue transport. Uses Redis by default. Set to `in-memory://` for testing. |
| `MESSENGER_CONSUMER_NAME` | `${HOSTNAME:-worker}` | Consumer identifier for the messenger worker. Defaults to the container hostname. |

## OAuth 2.0

| Variable | Default | Description |
|----------|---------|-------------|
| `OAUTH_PRIVATE_KEY_PATH` | `%kernel.project_dir%/config/secrets/oauth/private.key` | Path to the RSA private key for signing JWT access tokens. Generate with `app:oauth:generate-keys`. |
| `OAUTH_PUBLIC_KEY_PATH` | `%kernel.project_dir%/config/secrets/oauth/public.key` | Path to the RSA public key for verifying JWT access tokens. |
| `AUTH_SPA_CLIENT_ID` | `baander_dev_spa_00001` | Public ID of the first-party OAuth client that password and passkey login issue tokens to. The web and Electron apps both use it. Seeded by `app:auth:setup-clients`. |

Provision the RSA key files outside the image and set the two key-path variables to
readable paths inside the web container. The default paths resolve relative to the
project directory. Keep the private key readable only by the application user;
production images contain no OAuth keys.

### Token lifetimes

These are configured as parameters in `config/packages/auth.yaml` and can be overridden per-environment:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `auth.access_token.ttl` | `3600` (1 hour) | Access token lifetime in seconds. |
| `auth.refresh_token.ttl` | `2592000` (30 days) | Refresh token lifetime in seconds. |

## Web Push (VAPID)

| Variable | Default | Description |
|----------|---------|-------------|
| `VAPID_PUBLIC_KEY` | — | VAPID public key for browser push notifications. Generate with `php bin/console app:generate-vapid-keys`. |
| `VAPID_PRIVATE_KEY` | — | VAPID private key. Keep this secret. |

## Mail

| Variable | Default | Description |
|----------|---------|-------------|
| `MAILER_DSN` | `smtp://localhost:1025` | Mailer transport DSN. Required for email features (password reset, notifications). Use your provider's SMTP URL in production. |
| `MAIL_FROM_ADDRESS` | `noreply@localhost` | From address for outgoing email. |
| `MAIL_FROM_NAME` | `Bånder` | From name for outgoing email. |

## External APIs

All API keys are optional — Baander works without them, but metadata enrichment will be limited to what's available locally.

| Variable | Default | Description |
|----------|---------|-------------|
| `DISCOGS_TOKEN` | — | Discogs API personal access token. Get one from your [Discogs settings](https://www.discogs.com/settings/developers). |
| `LASTFM_API_KEY` | — | Last.fm API key for artist/track metadata lookup. |
| `LASTFM_API_SECRET` | — | Last.fm API shared secret. |
| `SPOTIFY_CLIENT_ID` | — | Spotify API client ID for album art and metadata. |
| `SPOTIFY_CLIENT_SECRET` | — | Spotify API client secret. |
| `TASTE_DIVE_API_KEY` | — | TasteDive API key for music recommendations. |
| `MUSICBRAINZ_APP_NAME` | `Bånder` | Application name sent in MusicBrainz API requests (required by their terms of service). |
| `MUSICBRAINZ_VERSION` | `1.0.0` | Application version sent in MusicBrainz API requests. |
| `MUSICBRAINZ_CONTACT` | `noreply@localhost` | Contact email sent in MusicBrainz API requests. |

## Security

### Rate limiting

Limits are set in `config/packages/auth.yaml`. Each limit feeds a Symfony rate limiter in `config/packages/framework.yaml`, and each limiter has its own Redis pool, so the admin **Rate Limits** tab and `app:rate-limiter:clear` can reset one limiter without touching the others.

| Parameter | Default | Description |
|-----------|---------|-------------|
| `auth.rate_limit.anonymous_api.max_requests` | `360` | Max `/api` requests per client IP for requests without an authenticated user (sliding window). |
| `auth.rate_limit.anonymous_api.window` | `60` (1 min) | Window in seconds for anonymous API requests. |
| `auth.rate_limit.authenticated_api.max_requests` | `1800` | Max `/api` requests per authenticated user (sliding window). |
| `auth.rate_limit.authenticated_api.window` | `60` (1 min) | Window in seconds for authenticated API requests. |
| `auth.rate_limit.login.max_attempts` | `20` | Max login attempts per IP within the window. |
| `auth.rate_limit.login.window` | `300` (5 min) | Window in seconds for both login limits. |
| `auth.rate_limit.login_per_email.max_attempts` | `10` | Max login attempts per IP and email pair (prevents distributed brute force). |
| `auth.rate_limit.passkey.max_attempts` | `40` | Max passkey sign-in requests per IP within the window. One sign-in uses two requests. |
| `auth.rate_limit.passkey.window` | `300` (5 min) | Window in seconds for passkey sign-in. |
| `auth.rate_limit.register.max_attempts` | `10` | Max registration attempts per IP within the window. |
| `auth.rate_limit.register.window` | `900` (15 min) | Window in seconds for registration. |
| `auth.rate_limit.password_reset.max_attempts` | `10` | Max password reset requests per IP within the window. |
| `auth.rate_limit.password_reset_per_email.max_attempts` | `10` | Max password reset requests per account (normalized email) within the window. |
| `auth.rate_limit.password_reset.window` | `900` (15 min) | Window in seconds for both password reset limits. |
| `auth.rate_limit.refresh.max_attempts` | `60` | Max token refresh requests per refresh token within the window. |
| `auth.rate_limit.refresh.window` | `60` (1 min) | Window in seconds for token refresh. |

Each limiter counts requests per key:

| Limiter | Applies to | Key |
|---------|------------|-----|
| `anonymous_api` | Every `/api` request without an authenticated user, including requests whose credentials are rejected | Client IP |
| `authenticated_api` | Every `/api` request with an authenticated user | User ID |
| `auth_login_ip` | `POST /api/auth/login` | Client IP |
| `auth_login_ip_email` | `POST /api/auth/login` | Client IP and email |
| `auth_passkey_ip` | `POST /api/auth/passkey/authenticate/options`, `POST /api/auth/passkey/authenticate`, `POST /api/auth/login/passkey` | Client IP |
| `auth_register_ip` | `POST /api/auth/register` | Client IP |
| `auth_password_reset_ip` | `POST /api/auth/password/reset-request` | Client IP |
| `auth_password_reset_email` | `POST /api/auth/password/reset-request` | Lower-cased email |
| `auth_refresh_client` | `POST /api/auth/refresh` | Refresh token, or client IP when the body has none |
| `config_check` | Admin configuration check (10 per minute) | One bucket shared by all callers |
| `batch_cover_extract` | `POST /api/albums/covers/extract` (10 per minute) | Client IP |
| `discovery_endpoint_ip` | Discovery registration and pairing (30 per minute) | Client IP |

The last three have fixed limits in `config/packages/framework.yaml` and no `auth.yaml` parameter.

A request over a limit gets `429 Too Many Requests` with a `Retry-After` header in seconds. The password reset account limit is the exception: over it, the endpoint returns the same `200` response as for any other address but issues no reset token, so the response never shows whether an account exists. The per-IP reset limit still answers `429`.

Media delivery routes are exempt from `anonymous_api` and `authenticated_api`, so playback is never throttled by the general API budget: the audio stream (`/api/stream/track`), the HLS and DASH manifests and segments under `/api/transcode/{id}/` (`master.m3u8`, `media.m3u8`, `manifest.mpd`, `init`, `segment`), subtitle playlists and segments, image files (`/api/images/{id}/file`) and album covers (`GET /api/albums/{id}/cover`). Health, readiness and metrics endpoints (`/health`, `/ready`, `/live`, `/metrics`) are outside `/api` and are never limited.

With `APP_ENV=dev`, only the `config_check` and `auth_password_reset_email` limits apply; the others are skipped.

Under Swoole, the application trusts `X-Forwarded-For` from the directly connected peer, normally the bundled nginx, and uses the last address in it as the client IP. If another proxy sits in front of nginx, every client shares that proxy's address and its anonymous budget unless nginx restores the real client address (`set_real_ip_from`). Do not expose the Swoole port directly: a client could then send its own `X-Forwarded-For` and evade every per-IP limit.

### Passkeys (WebAuthn)

Configured in `config/packages/auth.yaml`:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `auth.passkey.timeout` | `300000` (5 min) | WebAuthn ceremony timeout in milliseconds. |
| `auth.passkey.authenticator_attachment` | `platform` | Prefer platform authenticators (Touch ID, Windows Hello). |
| `auth.passkey.user_verification` | `preferred` | Whether to require user verification during authentication. |
| `auth.passkey.resident_key` | `preferred` | Whether credentials should be discoverable (passkeys). |
| `auth.passkey.attestation` | `none` | Attestation conveyance preference. |

### Passwords (Argon2id)

| Variable | Default | Description |
|----------|---------|-------------|
| `ARGON2ID_MEMORY_COST` | `65536` | Argon2id memory cost, in KiB. |
| `ARGON2ID_TIME_COST` | `4` | Argon2id time cost (iterations). |
| `ARGON2ID_THREAD_COST` | `3` | Argon2id parallelism (thread count). |

### Password reset & timeout

| Variable | Default | Description |
|----------|---------|-------------|
| `PASSWORD_RESET_EXPIRE` | `60` | Password reset link lifetime, in minutes. |
| `PASSWORD_TIMEOUT` | `10800` | Seconds before a password is considered stale for re-authentication prompts. |

### Token binding

Constrains how many distinct IPs a single token can bind to, to detect token theft and sharing.

| Variable | Default | Description |
|----------|---------|-------------|
| `TOKEN_BINDING_MAX_IP_CHANGES` | `10` | Maximum number of distinct IPs a token may bind to. |
| `TOKEN_BINDING_CONCURRENT_IP_WINDOWS_SECONDS` | `300` | Window (seconds) over which concurrent IPs are counted. |
| `TOKEN_BINDING_MAX_CONCURRENT_IPS` | `1` | Maximum concurrent IPs allowed per token. |
| `TOKEN_BINDING_MIN_IP_CHANGE_INTERVAL_MINUTES` | `5` | Minimum minutes between allowed IP changes. |

## Server

| Variable | Default | Description |
|----------|---------|-------------|
| `DEFAULT_URI` | `https://localhost` | Base URL used by the router for generating absolute URLs. Should match `APP_URL` in production. |

### Swoole

Configured in `config/packages/swoole.yaml`:

| Setting | Default | Description |
|---------|---------|-------------|
| `swoole.http_server.host` | `0.0.0.0` | Address to bind the HTTP server. |
| `swoole.http_server.port` | `9501` | HTTP server port. |

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
| `FFMPEG_PATH` | `/usr/local/bin/ffmpeg` | Path to the FFmpeg binary. |
| `FFPROBE_PATH` | `/usr/local/bin/ffprobe` | Path to the FFprobe binary. |

## Stream Authentication

| Variable | Default | Description |
|----------|---------|-------------|
| `STREAM_URL_HMAC_SECRET` | `change-me-...` | Secret used to HMAC-sign streaming URLs. **Set a random 64-character value in production.** |

## HTTP

API routes accept cross-origin requests only from the origin in `APP_URL`. Two path prefixes allow any origin: `/.well-known/`, which serves the public JWKS keys, and `/api/discovery/`, whose endpoints still require authentication. The policy is set in `config/packages/nelmio_cors.yaml`; no environment variable changes it.

## Job Monitoring

| Variable | Default | Description |
|----------|---------|-------------|
| `JOB_MONITOR_MAX_PAYLOAD_SIZE` | `20000000` | Maximum payload size, in bytes, stored per job monitor entry. |

## Tips

- Never commit `.env.prod` — add it to `.gitignore` and configure it through your deployment tooling.
- All env vars in `.env` can be overridden by setting real environment variables in your container runtime (Docker Compose, Kubernetes, etc.).
- For production, generate a unique `APP_SECRET` with `php -r 'echo bin2hex(random_bytes(32));'`.
- OAuth keys should be at least 2048-bit RSA. Generate with `openssl genrsa -out private.key 2048` and `openssl rsa -in private.key -pubout > public.key`.
- Rate limiting uses Redis — if Redis is unavailable, rate limits are not enforced.
