# Authentication and Rate Limiting

This page covers OAuth 2.0 signing keys, token lifetimes, delegated token scopes,
rate limiters, WebAuthn passkeys, TOTP, Argon2id password hashing, self-service
account recovery and token binding. Most of these are set through environment variables and
`config/packages/auth.yaml`; each section names where its values come from.

## OAuth 2.0

| Variable | Default | Description |
|----------|---------|-------------|
| `OAUTH_PRIVATE_KEY_PATH` | `%kernel.project_dir%/config/secrets/oauth/private.key` | Path to the RSA private key for signing JWT access tokens. Generate with `app:oauth:generate-keys`. |
| `OAUTH_PUBLIC_KEY_PATH` | `%kernel.project_dir%/config/secrets/oauth/public.key` | Path to the RSA public key for verifying JWT access tokens. |
| `AUTH_SPA_CLIENT_ID` | `baander_dev_spa_00001` | Public ID of the first-party OAuth client that password and passkey login issue tokens to. The web and Electron apps both use it. Seeded by `app:auth:setup-clients`. Only tokens of this client act with the user's full authority; see [Delegated tokens and scopes](#delegated-tokens-and-scopes). |
| `AUTH_DPOP_EXTRA_ORIGINS` | empty | Comma-separated origins, besides the origin of `APP_URL`, that a DPoP proof's `htu` may name, such as a LAN address the installation also answers on. Baander never takes the expected origin from the scheme and `Host` a request arrived with. An entry that is not an absolute `http` or `https` URL stops the application from booting. |
| `AUTH_TOTP_ENCRYPTION_KEY` | development value in `.env` | Key that encrypts stored TOTP secrets: 32 random bytes, base64-encoded. Generate one with `openssl rand -base64 32`. Production refuses to boot without a private value; see [Hardening checklist](../maintenance/security.md#hardening-checklist). |

A DPoP proof names the URL the client sent it to. Behind a TLS-terminating proxy the
request reaches Baander as plain HTTP, so Baander checks the proof against `APP_URL`
and `AUTH_DPOP_EXTRA_ORIGINS` instead of the request's own scheme. The shipped
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

Tokens issued to the first-party client act with the user's full authority. Every other client's tokens are delegated, including the user's own personal access clients: the user counts as a plain user, without any administrator role, and the token reaches only the routes its scopes cover. Any other route answers `403` with `insufficient_scope`.

| Scope | What a delegated token can do |
|-------|-------------------------------|
| `profile` | Read the user's public ID, name and account creation date |
| `email` | Read the same, plus the user's email address and whether it is verified |
| `library` | Browse and play the libraries the user is a member of, control playback on their devices, and use favorites, listening history, recommendations and radio |
| `playlist` | Read, create, change, share and delete the user's playlists |

No scope reaches administration, account security (passwords, email addresses, passkeys, TOTP, sessions), consent and device approval, notifications, listening parties, or preferences and settings. The consent and device pages show the user one line for each scope the client would get.

The issuer is `APP_URL`. `app:auth:setup-clients` creates only the first-party client; administrators register device, public and confidential clients with the `app:oauth:client:*` commands or the admin API (see [OAuth Clients](../administration/user-management.md#oauth-clients)).


## Security

### Rate limiting

Limits are set in `config/packages/auth.yaml`. They feed the Symfony policies in
`config/packages/framework.yaml`; all 26 configured limiters consume state through
atomic Redis compare-and-set. Each limiter has its own namespace, so the admin
**Rate Limits** tab and `app:rate-limiter:clear` reset its generation without
changing other limiters. The catalog reports `storageNamespace`, not a cache pool.
Changing a limiter's effective configuration starts fresh subject budgets; it does
not carry a shared budget across different configurations during a rolling change.
See [Rate Limiter Monitor](../operations/health-and-metrics.md#rate-limiter-monitor).

| Parameter | Default | Description |
|-----------|---------|-------------|
| `auth.rate_limit.anonymous_api.max_requests` | `360` | Max `/api` requests per client IP for requests without an authenticated user (sliding window). |
| `auth.rate_limit.anonymous_api.window` | `60` (1 min) | Window in seconds for anonymous API requests. |
| `auth.rate_limit.authenticated_api.max_requests` | `1800` | Max `/api` requests per authenticated user (sliding window). |
| `auth.rate_limit.authenticated_api.window` | `60` (1 min) | Window in seconds for authenticated API requests. |
| `auth.rate_limit.login.max_attempts` | `20` | Max login attempts per IP within the window. |
| `auth.rate_limit.login.window` | `300` (5 min) | Window in seconds for the per-IP and the per-IP-and-email login limits. |
| `auth.rate_limit.login_per_email.max_attempts` | `10` | Max login attempts per IP and email pair within the window. |
| `auth.rate_limit.login_failures_per_email.max_attempts` | `10` | Max failed logins per account (normalized email), from all IPs together, within the sliding window. |
| `auth.rate_limit.login_failures_per_email.window` | `300` (5 min) | Window in seconds for the per-account failure limit. |
| `auth.rate_limit.step_up.max_attempts` | `10` | Max step-up attempts per account within the window, from all IPs together: the password, plus a TOTP code when TOTP is on, that an email change, TOTP setup and passkey registration ask for. |
| `auth.rate_limit.step_up.window` | `900` (15 min) | Window in seconds for step-up attempts. |
| `auth.rate_limit.passkey.max_attempts` | `40` | Max passkey sign-in requests per IP within the window. One sign-in uses two requests. |
| `auth.rate_limit.passkey.window` | `300` (5 min) | Window in seconds for passkey sign-in. |
| `auth.rate_limit.register.max_attempts` | `10` | Max registration attempts per IP within the window. |
| `auth.rate_limit.register.window` | `900` (15 min) | Window in seconds for registration. |
| `auth.rate_limit.password_reset.max_attempts` | `10` | Max password reset requests and redemptions per IP within the window. |
| `auth.rate_limit.password_reset_per_email.max_attempts` | `10` | Max password reset requests per account (normalized email) within the window. |
| `auth.rate_limit.password_reset.window` | `900` (15 min) | Window in seconds for both password reset limits. |
| `auth.rate_limit.refresh.max_attempts` | `60` | Max first-party token refresh requests per refresh token within the window. |
| `auth.rate_limit.refresh.window` | `60` (1 min) | Window in seconds for token refresh. |
| `auth.rate_limit.email_verification.max_attempts` | `10` | Max email verification attempts and verification resend requests per IP within the window. |
| `auth.rate_limit.email_verification.window` | `900` (15 min) | Window in seconds for the per-IP verification limit. |
| `auth.rate_limit.email_verification_resend_per_user.max_attempts` | `3` | Max verification emails a signed-in user can ask for within the window. |
| `auth.rate_limit.email_verification_resend_per_user.window` | `3600` (1 hour) | Window in seconds for the per-user resend limit. |
| `auth.rate_limit.oauth_token.max_attempts` | `120` | Max OAuth token endpoint requests per IP within the window. A polling device makes about 12 per minute at the default interval. |
| `auth.rate_limit.oauth_token.window` | `60` (1 min) | Window in seconds for both token endpoint limits. |
| `auth.rate_limit.oauth_token_client.max_attempts` | `600` | Max token endpoint requests per client within the `oauth_token` window, charged after the client authenticates. A client with a secret or keys has one allowance for all addresses; a public client has one per caller network. |
| `auth.rate_limit.oauth_par.max_attempts` | `120` | Max pushed authorization requests per IP within the window. Each one stores a request for up to `auth.par.request_uri_ttl` seconds. |
| `auth.rate_limit.oauth_par.window` | `60` (1 min) | Window in seconds for both pushed authorization request limits. |
| `auth.rate_limit.oauth_par_client.max_attempts` | `600` | Max pushed authorization requests per client within the `oauth_par` window, charged after the client authenticates and counted as at the token endpoint. |
| `auth.rate_limit.oauth_authorize.max_attempts` | `30` | Max authorization endpoint requests per IP within the window. |
| `auth.rate_limit.oauth_authorize.window` | `60` (1 min) | Window in seconds for the authorization endpoint. |
| `auth.rate_limit.oauth_device_authorize.max_attempts` | `20` | Max device authorization requests per IP within the window. Each request stores a device code. |
| `auth.rate_limit.oauth_device_authorize.window` | `300` (5 min) | Window in seconds for device authorization. |
| `auth.rate_limit.oauth_device_verify.max_attempts` | `30` | Max user code lookups, approvals and denials per IP within the window. This bounds user code guessing. |
| `auth.rate_limit.oauth_device_verify.window` | `300` (5 min) | Window in seconds for user code lookups, approvals and denials. |

Each limiter counts requests per key:

| Limiter | Applies to | Key |
|---------|------------|-----|
| `anonymous_api` | Every `/api` request without an authenticated user, including requests whose credentials are rejected | Client IP |
| `authenticated_api` | Every `/api` request with an authenticated user | User ID |
| `auth_login_ip` | `POST /api/auth/login` | Client IP |
| `auth_login_ip_email` | `POST /api/auth/login` | Client IP and email |
| `auth_login_email_failures` | Failed `POST /api/auth/login` attempts: an unknown address, a wrong password or a rejected TOTP code (sliding window) | Normalized email |
| `auth_step_up_user` | `PUT /api/auth/me/email`, `POST /api/auth/totp/setup`, `POST /api/auth/passkey/options` | User ID |
| `auth_passkey_ip` | `POST /api/auth/passkey/authenticate/options`, `POST /api/auth/passkey/authenticate`, `POST /api/auth/login/passkey` | Client IP |
| `auth_register_ip` | `POST /api/auth/register` | Client IP |
| `auth_password_reset_ip` | `POST /api/auth/password/reset-request`, `POST /api/auth/password/reset` (one shared bucket) | Client IP |
| `auth_password_reset_email` | `POST /api/auth/password/reset-request` | Lower-cased email |
| `auth_refresh_client` | `POST /api/auth/refresh` | SHA-256 hash of the refresh token, or client IP when the body has none |
| `auth_email_verification_ip` | `POST /api/auth/email/verify`, `POST /api/auth/me/email/verification` (one shared bucket) | Client IP |
| `auth_email_verification_user` | `POST /api/auth/me/email/verification` | User ID |
| `oauth_token_ip` | `POST /api/oauth/token` | Client IP |
| `oauth_token_client` | `POST /api/oauth/token`, after client authentication | Client public ID; for a public client, the public ID and the caller's `/24` (IPv4) or `/64` (IPv6) network |
| `oauth_par_ip` | `POST /api/oauth/par` | Client IP |
| `oauth_par_client` | `POST /api/oauth/par`, after client authentication | As `oauth_token_client` |
| `oauth_authorize_ip` | `GET` and `POST /api/oauth/authorize` | Client IP |
| `oauth_device_authorize_ip` | `POST /api/oauth/device/authorize` | Client IP |
| `oauth_device_verify_ip` | `GET /api/oauth/device/verify`, `POST /api/oauth/device/approve` | Client IP |
| `config_check` | Admin configuration check (10 per minute) | One bucket shared by all callers |
| `batch_cover_extract` | `POST /api/albums/covers/extract` (10 per minute) | Client IP |
| `musicbrainz` | Every MusicBrainz request and every Cover Art Archive cover fetch, from workers and web requests alike (1 per second) | One bucket shared by all callers |
| `musicbrainz_user` | `GET /api/metadata/browse/*`, and `GET /api/metadata/search/*` when it searches MusicBrainz, the default source (1 per 10 seconds) | User ID |
| `lrclib` | Every LRCLIB request, from workers and web requests alike (1 per 2 seconds) | One bucket shared by all callers |
| `discovery_endpoint_ip` | Discovery registration and pairing (30 per minute) | Client IP |

The last six have fixed limits in `config/packages/framework.yaml` and no `auth.yaml` parameter.

The per-IP limits run before Baander authenticates the request. The per-account login failure and step-up limits run once the account's address or user is known, and the per-client limits once the client has authenticated, so a caller who merely names a client in `client_id` cannot use up that client's allowance.

The three login limits count different things. `auth_login_ip` and `auth_login_ip_email` count every `POST /api/auth/login` from an address, DPoP nonce challenges and TOTP prompts included. They slow one address down but do not stop an attack spread over many addresses: each new address brings a fresh budget. `auth_login_email_failures` counts only rejected passwords and TOTP codes for an email address, from every address together, and it charges an address that belongs to no account exactly as one that does, so the limit reveals nothing about which accounts exist. A nonce challenge, the TOTP prompt and a successful login cost nothing against it. Once an address reaches the limit, every login for it answers `429`, the right password included, until older failures slide out of the window. An attacker who keeps guessing can therefore lock the real user out for as long as the attack lasts; clear the limiter from the **Rate Limits** tab or with `app:rate-limiter:clear auth_login_email_failures` to let the user in sooner.

A sign-in with TOTP costs more requests than one without it, because a failed login answer, the TOTP prompt included, carries no new DPoP nonce and the web app starts each attempt without one. With no wrong code it takes four `POST /api/auth/login` requests: a nonce challenge, the password, a second nonce challenge and the password with the code. Each wrong code adds two, so a sign-in with `n` wrong codes costs `4 + 2n` requests against `auth_login_ip` and `auth_login_ip_email`. At the default of 10 per IP and email in 5 minutes, a user can mistype the code three times in one window.

A request over a limit gets `429 Too Many Requests` with a `Retry-After` header in seconds. The password reset account limit and the verification resend user limit are the exceptions. Over the reset account limit, the endpoint returns the same `200` response as for any other address but issues no reset token, so the response never shows whether an account exists. Over the resend user limit, the endpoint returns its usual `200` response but sends no email. The per-IP reset and verification limits still answer `429`.

The `musicbrainz` limit never answers `429`; callers wait for their turn instead. MusicBrainz allows one request per second from an address and answers `503` to an address that sends faster. Every MusicBrainz request and Cover Art Archive cover fetch, from any worker or web process, reserves the next free turn in this shared bucket and waits for it; each turn starts at least one second after the one before it. Background work waits as long as the queue ahead of it takes. A MusicBrainz search or lookup on the metadata search and browse routes, which any signed-in user may call, first spends one call of the user's `musicbrainz_user` allowance and answers `429` when the allowance is spent. It then waits at most 10 seconds for a turn, and answers `503 Service Unavailable` when no turn comes within those 10 seconds or when the limiter state in Redis cannot be read.

The `lrclib` limit never answers `429` either. Every LRCLIB request, from any worker or web process, reserves the next free turn in its shared bucket and waits for it; each turn starts at least two seconds after the one before it. The fetch of one song, a lyrics search and an apply, which only administrators may call, wait at most 10 seconds, and a queued lyrics fetch at most 30. A request whose turn comes later is not sent: LRCLIB then counts as unavailable, as it does when the limiter state in Redis cannot be read, and the lyrics routes answer `503`. Clearing `lrclib`, from the **Rate Limits** tab or with `app:rate-limiter:clear lrclib`, resets these turns only. A pause that LRCLIB asked for with a `429`, or a `503` with a `Retry-After` header, is stored outside the limiter and ends on its own, after one hour at most; see [External APIs](../media/external-apis.md#supported-apis).

Media delivery routes are exempt from `anonymous_api` and `authenticated_api`, so playback is never throttled by the general API budget: the audio stream (`/api/stream/track`), the original-file streams (`/api/stream/video`, `/api/stream/original/track`), the HLS and DASH manifests and segments under `/api/transcode/{id}/` (`master.m3u8`, `media.m3u8`, `manifest.mpd`, `init`, `segment`), the completed-video playlists and segments under `/api/transcode/artifacts/`, subtitle playlists and segments, image files (`/api/images/{id}/file`) and album covers (`GET /api/albums/{id}/cover`). Health, readiness and metrics endpoints (`/health`, `/ready`, `/live`, `/metrics`) are outside `/api` and are never limited.

With `APP_ENV=dev`, only the `config_check`, `auth_password_reset_email`, `auth_email_verification_user`, `auth_login_email_failures`, `auth_step_up_user`, `musicbrainz`, `musicbrainz_user` and `lrclib` limits apply; the others are skipped.

Both HTTP layers read `TRUSTED_PROXIES`, a comma-separated list of trusted proxy
addresses or CIDRs that defaults to empty. With that default, a direct client
cannot select a rate-limit identity by supplying `X-Forwarded-For`. Behind nginx or another proxy,
configure only its actual source addresses or deliberately restricted CIDRs and
make that proxy replace incoming forwarding headers. Otherwise clients share the
proxy's address and its anonymous budget. Do not enable broad subnet trust merely
because the service runs in Docker. See
[Request identity and proxy deployment](../../part-2-developer-guide/architecture/application-threat-model.md#request-identity-and-proxy-deployment).

### Passkeys (WebAuthn)

Configured in `config/packages/auth.yaml`:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `auth.passkey.timeout` | `300000` (5 min) | WebAuthn ceremony timeout in milliseconds. |
| `auth.passkey.authenticator_attachment` | `platform` | Prefer platform authenticators (Touch ID, Windows Hello). |
| `auth.passkey.user_verification` | `preferred` | Whether to require user verification during authentication. Whatever the setting, an account with TOTP enabled accepts a passkey sign-in only with user verification, such as a PIN or fingerprint on the authenticator. |
| `auth.passkey.resident_key` | `preferred` | Whether credentials should be discoverable (passkeys). |
| `auth.passkey.attestation` | `none` | Attestation conveyance preference. |

Passkey challenges are stored only in Redis, and each works once. While Redis is
unavailable, passkey registration and sign-in answer `503` with `Retry-After`; password
sign-in is unaffected by the challenge store. Adding a passkey needs a verified email
address and the user's current password, plus a TOTP code when TOTP is on.

### TOTP

| Parameter | Default | Description |
|-----------|---------|-------------|
| `auth.totp.window` | `1` | Number of 30-second steps accepted on either side of the current one, for clock skew; `0` accepts the current step only. Set in `config/packages/auth.yaml`. |
| `auth.totp.issuer` | the application name | Issuer name the user's authenticator app shows. |

Each code works once: after a code signs the user in or confirms a change, the same
code is refused, so a user who needs two codes in a row waits for the next one. Baander
stores TOTP secrets encrypted under `AUTH_TOTP_ENCRYPTION_KEY` (see [OAuth 2.0](#oauth-20)).
Turning TOTP on needs a verified email address and the current password. A user who
loses their authenticator, or whose secret no longer opens under the configured key,
gets their TOTP turned off by an operator with
[`app:user:reset-totp`](../commands/app-user-reset-totp.md) or the admin API's
`POST /api/admin/users/{publicId}/reset-totp`, which also signs them out everywhere.

### Passwords (Argon2id)

`config/packages/security.yaml` sets the Argon2id password hasher's memory cost
to 65536 KiB and its time cost to 4.

### Password reset

| Variable | Default | Description |
|----------|---------|-------------|
| `PASSWORD_RESET_EXPIRE` | `60` | Lifetime of a password reset token, in minutes. Must be at least `1`. |

A user has at most one outstanding reset token; a new request replaces it. The token is removed when it is redeemed, when the account is deleted, and when the account's email address or password changes. Baander stores only a SHA-256 hash of the token.

Self-service reset needs working email. When a user asks for a reset on the web login page, `POST /api/auth/password/reset-request` issues a token for an existing account and emails a link through `MAILER_DSN` (see [Mail](environment-variables.md#mail)). The link opens `APP_URL/reset-password`, so `APP_URL` must be the address users reach the web app at. The token sits in the link's fragment (`#token=…`); browsers do not send fragments to the server, so the token stays out of access logs and `Referer` headers. The email states the link's lifetime from `PASSWORD_RESET_EXPIRE`.

Baander sends the email after the HTTP response has gone out, so the request takes as long for an unknown address as for a real account. A failed send does not change the response either. Baander logs the failure with the user ID but without the token or the address, and does not retry; the user can ask for a new link. The email is written in the user's [email language](server-settings.md#email-language), which Baander looks up when it sends the email, after the response.

Without working email, reset a forgotten password with [`app:user:reset-password`](../commands/app-user-reset-password.md) or from the admin panel.

A password change revokes the sessions that the old password started. Redeeming a reset token, an administrator reset and `app:user:reset-password` revoke all of the user's access and refresh tokens. When users change their own password, the session that made the change stays signed in and their other sessions are revoked, including one that signed in while the change was being saved.

A reset link also settles the account's email address, because opening it proves the user reads that mailbox. If the address was verified, a pending email change is dropped. If it was never verified, someone else may have registered the account under that address, so the reset verifies the address and removes the account's TOTP and passkeys along with its sessions.

### Email verification

| Parameter | Default | Description |
|-----------|---------|-------------|
| `auth.email_verification_token.ttl` | `86400` (24 hours) | Lifetime of an email verification token, in seconds. Set in `config/packages/auth.yaml`; must be at least `60`. |

Verification confirms that the user owns the account's email address. Until it does, Baander sends the account no notification email. Operator-created accounts start verified; every other account needs working email to verify (see [Mail](environment-variables.md#mail)).

Baander emails a verification link when a user registers and when a user changes their own address in **Settings**. Registration answers the same way whether or not the address already has an account; for a taken address Baander creates nothing and instead emails the account's owner that someone tried to sign up with it, with a link to reset the password. A user's change needs their current password, plus a TOTP code when TOTP is on, and stays pending until they open the link sent to the new address; until then the account keeps its old address. The request gets the same answer whether or not another account uses the new address. When the change takes effect, Baander emails the old address that the account's address changed; an administrator's change sends the same notice. An address an administrator sets in the admin panel or with [`app:user:change-email`](../commands/app-user-change-email.md) takes effect at once and counts as verified, and [`app:user:mark-email-verified`](../commands/app-user-mark-email-verified.md) verifies a user's current address without email. Adding a passkey or turning on TOTP needs a verified address. The link opens `APP_URL/verify-email`, so `APP_URL` must be the address users reach the web app at. As with password reset, the token sits in the link's fragment (`#token=…`) and stays out of access logs and `Referer` headers. A signed-in user whose address is unverified can ask for a new link in **Settings**, which calls `POST /api/auth/me/email/verification`. That endpoint answers the same way whether or not it sent an email.

A user has at most one outstanding verification token; a new link replaces it. A token verifies only the address it was sent to. It is removed when it is redeemed, when the account is deleted, and when the account's email address changes, so a link sent to an old address stops working. Baander stores only a SHA-256 hash of the token, with the address.

Baander sends the email after the HTTP response has gone out, or at once when the change comes from the command line. A failed send never fails the registration or email change that caused it. Baander logs the failure with the user ID but without the token or the address, and does not retry; the user can ask for a new link. Like the reset email, the verification email is written in the user's [email language](server-settings.md#email-language).

### Token binding

Every access token is bound to the DPoP key that requested it, and every authorization code to the DPoP key its pushed request named; see
[OAuth clients and cross-origin access](../maintenance/security.md#oauth-clients-and-cross-origin-access).
A token can also be bound to a client fingerprint; no token is bound to IP addresses.
When the request that issued a token sent the `X-Baander-Client-Fingerprint`
header, every later request with that token must send the same value, or Baander
rejects the token. A refreshed token keeps the fingerprint of the token it
replaces. A token issued without the header is not fingerprint-bound and ignores
it. No setting configures these checks.

