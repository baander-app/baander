# Authentication

Configure OAuth signing keys, token lifetimes and client access here. Each section
names the environment variables or `config/packages/auth.yaml` parameters it uses.
For request budgets, use [Rate limits](rate-limits.md). Passkeys, TOTP, password
reset and email verification have their own [account security reference](account-security.md).

## OAuth 2.0

| Variable | Default | Description |
|----------|---------|-------------|
| `OAUTH_PRIVATE_KEY_PATH` | `%kernel.project_dir%/config/secrets/oauth/private.key` | Path to the RSA private key for signing JWT access tokens. Generate with `app:oauth:generate-keys`. |
| `OAUTH_PUBLIC_KEY_PATH` | `%kernel.project_dir%/config/secrets/oauth/public.key` | Path to the RSA public key for verifying JWT access tokens. |
| `AUTH_SPA_CLIENT_ID` | `baander_dev_spa_00001` | Public ID of the first-party OAuth client that password and passkey login issue tokens to. The web and Electron apps both use it. Seeded by `app:auth:setup-clients`. Only tokens of this client from a password or passkey sign-in act with the user's full authority, and the OAuth endpoints refuse this client; see [Delegated tokens and scopes](#delegated-tokens-and-scopes). |
| `AUTH_EXTRA_ORIGINS` | empty | Comma-separated origins, besides the origin of `APP_URL`, that the installation also answers on, such as a LAN address. A DPoP proof's `htu` may name any of them. Passkey registration and sign-in accept those whose host is the host of `APP_URL` or a subdomain of it; the relying party ID is the host of `APP_URL`. A request that arrives on a listed origin gets that origin as the web app's API URL, as the WebSocket origin its Content-Security-Policy allows, and as the base of absolute URLs in API responses, such as cover image URLs; any other `Host`, sent directly or forwarded by a trusted proxy, gets the origin of `APP_URL`. The WebSocket handshake does not read this list: it accepts only the origin of `APP_URL`, or the origins of the `app.websocket.allowed_origins` parameter in `config/services.yaml` when that is set, so a web app opened on a listed origin is refused its WebSocket connection. Baander never takes the expected origin from the scheme and `Host` a request arrived with. An entry that is not an absolute `http` or `https` URL stops the application from booting. |
| `AUTH_TOTP_ENCRYPTION_KEY` | development value in `.env` | Key that encrypts stored TOTP secrets: 32 random bytes, base64-encoded. Generate one with `openssl rand -base64 32`. Production refuses to boot without a private value; see [Hardening checklist](../maintenance/security.md#hardening-checklist). |

A DPoP proof names the URL the client sent it to. Behind a TLS-terminating proxy the
request reaches Baander as plain HTTP, so Baander checks the proof against `APP_URL`
and `AUTH_EXTRA_ORIGINS` instead of the request's own scheme. The shipped
production nginx passes the upstream proxy's `X-Forwarded-Proto` on, and uses its own
scheme only when none arrived.

Changing `AUTH_TOTP_ENCRYPTION_KEY` makes every stored TOTP secret unreadable. Users
with TOTP then cannot sign in, and their login answers `AUTH_TOTP_UNAVAILABLE`, until
an operator resets their TOTP with [`app:user:reset-totp`](../commands/app-user-reset-totp.md).
A database restored without the key its secrets were sealed with has the same effect.

Provision the RSA key files outside the image and set the two key-path variables to
readable paths inside the web container. The default paths resolve relative to the
project directory. Keep the private key readable only by the application user;
production images contain no OAuth keys.

### Token lifetimes

These are configured as parameters in `config/packages/auth.yaml` and can be overridden per-environment:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `auth.access_token.ttl` | `3600` (1 hour) | Access token lifetime in seconds. |
| `auth.refresh_token.ttl` | `2592000` (30 days) | Refresh token lifetime in seconds. Each rotation issues a refresh token with a fresh lifetime. Baander stores only the SHA-256 hash of each refresh token. |
| `auth.refresh.max_chain_lifetime` | `7776000` (90 days) | Absolute lifetime, in seconds, of a sign-in's chain of refresh tokens, counted from the sign-in. Rotation does not extend it; once it has passed, the user signs in again. |
| `auth.refresh.reuse_grace_seconds` | `60` | Lost-response grace, in seconds, measured on the database clock. For this long after a refresh token was consumed, presenting it again with a proof from the same DPoP key, while the pair it was exchanged for is still unused, issues a fresh pair and revokes that unused pair; a client whose refresh answer never arrived keeps its session. Any other reuse of a consumed token revokes its whole chain. At most `60`; `0` disables the grace. |
| `auth.auth_code.ttl` | `60` | Authorization code lifetime in seconds (authorization code grant with PKCE). The code is issued after the user's consent, so it only has to survive the redirect back to the client and the client's token request. |
| `auth.par.request_uri_ttl` | `300` (5 minutes) | How long a pushed authorization request stays usable, in seconds: the time a user has to sign in, with TOTP or a passkey if needed, and approve or deny. One decision uses the request up. |
| `auth.device_code.ttl` | `900` (15 minutes) | Device code lifetime in seconds (RFC 8628). |
| `auth.device_code.interval` | `5` | Minimum seconds between a device's token polls. A faster poll is answered with `slow_down` and adds 5 seconds to that device code's interval. |

### Authorization server

Clients other than Baander's own apps obtain tokens through the authorization code grant, the device authorization grant, and the refresh token grant. Every authorization code request is pushed first to `POST /api/oauth/par` (RFC 9126); the consent page accepts only the `request_uri` a push returned, never request parameters in its address. These parameters are also in `config/packages/auth.yaml`:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `auth.scopes.user_grants` | `profile`, `email`, `library`, `playlist` | Scopes a client other than Baander's own apps may be granted, through the authorization code grant or the device grant. Requested scopes outside this list are dropped, and a request left with none is refused with `invalid_scope`; there is no default scope. The metadata document at `/.well-known/oauth-authorization-server` lists them as `scopes_supported`. |
| `auth.oauth.authorization_page_uri` | `%auth.oauth.issuer%/oauth/authorize` | Absolute URI of the web app's consent page. The metadata document advertises it as `authorization_endpoint`. Clients send the browser there with only `client_id` and the `request_uri` from their push. The page looks the pushed request up with `GET /api/oauth/authorize`, sends the user's decision to `POST /api/oauth/authorize`, and then sends the browser to the redirect URI it gets back. |
| `auth.device.verification_uri` | `%auth.oauth.issuer%/device` | Absolute URI of the web app page where a signed-in user enters the code a device shows. The device authorization response returns it as `verification_uri`, and the same URI with `?user_code=` appended as `verification_uri_complete`. |

### Client assertions and key sets

Confidential clients registered with `private_key_jwt` authenticate with a signed JWT instead of a secret. Its `aud` must be the issuer, `APP_URL`, exactly. These parameters in `config/packages/auth.yaml` bound the assertions and the key sets Baander fetches:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `auth.client_assertion.max_lifetime` | `300` (5 minutes) | Longest lifetime a client assertion may have, in seconds: its `exp` may lie at most this far ahead and at most this far after its `iat`. The clock skew allowance is `auth.dpop.clock_skew` (60 seconds). Each assertion is accepted once while it is valid. |
| `auth.client_jwks.cache_ttl` | `3600` (1 hour) | How long a key set fetched from a client's `jwks_uri` is cached, in seconds. Replacing the client's keys makes the cached set unusable at once. |
| `auth.client_jwks.refetch_interval` | `60` | Shortest time, in seconds, between two fetches of a client's cached key set when an assertion names a key the set lacks. A client can therefore rotate its own keys without an administrator, while an unknown key cannot make Baander fetch on every request. |

Baander fetches a `jwks_uri` over `https` on port 443, only from a public address of that URI's host, without following redirects, within 3 seconds, and refuses a set larger than 64 KB. A request that needs a fetch gets `503` with `Retry-After` while the fetch fails or Redis is unavailable, and nothing is cached. While Redis is unavailable, every assertion gets `503`, because its `jti` cannot be recorded.

A key a client removes from the set it publishes at its `jwks_uri` stays usable until the cached copy expires, up to `auth.client_jwks.cache_ttl` seconds later, because Baander fetches the set again early only for a key it has not seen. To end a leaked key at once, replace the client's keys with [`app:oauth:client:replace-keys`](../commands/app-oauth-client-replace-keys.md) or the admin panel, giving the same URL again if it has not changed; the next request fetches the set afresh.

### Delegated tokens and scopes

Tokens issued to the first-party client act with the user's full authority when their session began with a password or passkey sign-in, which is the only way that client gets tokens: the pushed authorization, token and device endpoints refuse it. Every other client's tokens are delegated, including the user's own personal access clients: the user counts as a plain user, without any administrator role, and the token reaches only the routes its scopes cover. Any other route answers `403` with `insufficient_scope`.

| Scope | What a delegated token can do |
|-------|-------------------------------|
| `profile` | Read the user's public ID, name and account creation date |
| `email` | Read the same, plus the user's email address and whether it is verified |
| `library` | Browse and play the libraries the user is a member of, download their original track and video files, control playback on their devices, and use favorites, listening history, recommendations and radio. A signed stream URL fetched without a token needs no scope. |
| `playlist` | Read, create, change, share and delete the user's playlists |

No scope reaches administration, account security (passwords, email addresses, passkeys, TOTP, sessions), consent and device approval, notifications, listening parties, or preferences and settings. The consent and device pages show the user one line for each scope the client would get, and the user decides on every request. A delegated token of an account whose email address is unverified reaches only public routes and, with the `profile` or `email` scope, the user's own profile; see [Email verification](account-security.md#email-verification).

The issuer is `APP_URL`. `app:auth:setup-clients` creates only the first-party client; administrators register device, public and confidential clients with the `app:oauth:client:*` commands or the admin API (see [OAuth Clients](../administration/oauth-clients.md#oauth-clients)).


## Related configuration

- [Rate limits](rate-limits.md): request budgets, the identities charged, provider pacing and outage responses.
- [Account security](account-security.md): passkeys, TOTP, password hashing, reset links and email verification.
- [Sessions and access](../administration/sessions-and-access.md): what disable, logout and signed-media expiry end.
