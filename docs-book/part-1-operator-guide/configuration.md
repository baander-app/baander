# Configuration

All configuration in Baander is done through environment variables. They're defined in `.env` and consumed by Symfony's config files. Override them per-environment in `.env.test`, `.env.prod`, or in your deployment setup.

## Application

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_ENV` | `dev` | Symfony environment: `dev`, `prod`, or `test`. Controls debugging, caching, and error handling. |
| `APP_SECRET` | — | Cryptographic secret used for signing cookies and CSRF tokens. Generate a unique value for each deployment. |
| `APP_URL` | `https://baander.test` | Canonical URL of the application. Used for CORS configuration and URL generation. |
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
| `REDIS_PASSWORD` | `baander` | Password for the Redis instance. Used by cache pools, messenger transport, and session storage. |
| `REDIS_URL` | `redis://default:%env(REDIS_PASSWORD)%@redis:6379` | Full Redis DSN for the Sentinel cache client. The password is interpolated from `REDIS_PASSWORD`. |

Redis is used for:
- **Cache** — tag-aware caching for API responses, OAuth tokens, and user sessions
- **Messenger** — async job transport (scan jobs, notification delivery)
- **Rate limiting** — login, registration, and password reset attempt tracking

## Messenger

| Variable | Default | Description |
|----------|---------|-------------|
| `MESSENGER_TRANSPORT_DSN` | `redis://default:%env(REDIS_PASSWORD)%@redis:6379/messages` | DSN for the message queue transport. Uses Redis by default. Set to `in-memory://` for testing. |
| `MESSENGER_CONSUMER_NAME` | `${HOSTNAME:-worker}` | Consumer identifier for the messenger worker. Defaults to the container hostname. |

## OAuth 2.0

| Variable | Default | Description |
|----------|---------|-------------|
| `OAUTH_PRIVATE_KEY_PATH` | `config/secrets/oauth/private.key` | Path to the RSA private key for signing JWT access tokens. Generate with OpenSSL. |
| `OAUTH_PUBLIC_KEY_PATH` | `config/secrets/oauth/public.key` | Path to the RSA public key for verifying JWT access tokens. |
| `OAUTH_ENCRYPTION_KEY` | — | Defuse/php-encryption key for encrypting authorization codes. Required for multi-worker deployments. Leave empty for single-worker setups (a random key is generated per worker). |

### Token lifetimes

These are configured as parameters in `config/packages/auth.yaml` and can be overridden per-environment:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `auth.access_token.ttl` | `3600` (1 hour) | Access token lifetime in seconds. |
| `auth.refresh_token.ttl` | `2592000` (30 days) | Refresh token lifetime in seconds. |
| `auth.auth_code.ttl` | `600` (10 minutes) | Authorization code lifetime (PKCE flow). |
| `auth.device_code.ttl` | `900` (15 minutes) | Device code lifetime (RFC 8628). |

## Web Push (VAPID)

| Variable | Default | Description |
|----------|---------|-------------|
| `VAPID_PUBLIC_KEY` | — | VAPID public key for browser push notifications. Generate with `php bin/console app:generate-vapid-keys`. |
| `VAPID_PRIVATE_KEY` | — | VAPID private key. Keep this secret. |

## Mail

| Variable | Default | Description |
|----------|---------|-------------|
| `MAILER_DSN` | `null://null` | Mailer transport DSN. Use `smtp://mailpit:1025` for development, `smtp://user:pass@smtp.example.com:587` for production. |

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
| `MUSICBRAINZ_CONTACT` | `noreply@baander.test` | Contact email sent in MusicBrainz API requests. |

## Security

### Rate limiting

Configured in `config/packages/auth.yaml`:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `auth.rate_limit.login.max_attempts` | `5` | Max login attempts per IP within the window. |
| `auth.rate_limit.login.window` | `300` (5 min) | Window in seconds for login rate limiting. |
| `auth.rate_limit.login_per_email.max_attempts` | `3` | Max login attempts per IP+email combo (prevents distributed brute force). |
| `auth.rate_limit.register.max_attempts` | `3` | Max registration attempts per IP within the window. |
| `auth.rate_limit.register.window` | `900` (15 min) | Window in seconds for registration rate limiting. |
| `auth.rate_limit.password_reset.max_attempts` | `5` | Max password reset requests per IP within the window. |
| `auth.rate_limit.password_reset.window` | `900` (15 min) | Window in seconds for password reset rate limiting. |
| `auth.rate_limit.refresh.max_attempts` | `30` | Max token refresh requests per client within the window. |
| `auth.rate_limit.refresh.window` | `60` (1 min) | Window in seconds for token refresh rate limiting. |

### Passkeys (WebAuthn)

Configured in `config/packages/auth.yaml`:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `auth.passkey.timeout` | `300000` (5 min) | WebAuthn ceremony timeout in milliseconds. |
| `auth.passkey.authenticator_attachment` | `platform` | Prefer platform authenticators (Touch ID, Windows Hello). |
| `auth.passkey.user_verification` | `preferred` | Whether to require user verification during authentication. |
| `auth.passkey.resident_key` | `preferred` | Whether credentials should be discoverable (passkeys). |
| `auth.passkey.attestation` | `none` | Attestation conveyance preference. |

## Server

| Variable | Default | Description |
|----------|---------|-------------|
| `DEFAULT_URI` | `https://baander.test` | Base URL used by the router for generating absolute URLs. Should match `APP_URL` in production. |

### Swoole

Configured in `config/packages/swoole.yaml`:

| Setting | Default | Description |
|---------|---------|-------------|
| `swoole.http_server.host` | `0.0.0.0` | Address to bind the HTTP server. |
| `swoole.http_server.port` | `9501` | HTTP server port. |

## Tips

- Never commit `.env.prod` — add it to `.gitignore` and configure it through your deployment tooling.
- All env vars in `.env` can be overridden by setting real environment variables in your container runtime (Docker Compose, Kubernetes, etc.).
- For production, generate a unique `APP_SECRET` with `php -r 'echo bin2hex(random_bytes(32));'`.
- OAuth keys should be at least 2048-bit RSA. Generate with `openssl genrsa -out private.key 2048` and `openssl rsa -in private.key -pubout > public.key`.
- Rate limiting uses Redis — if Redis is unavailable, rate limits are not enforced.
