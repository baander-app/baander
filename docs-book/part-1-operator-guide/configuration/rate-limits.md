# Rate limits

Use this reference to identify the budget a request consumes, adjust a configurable limit, or diagnose a rate-limit response. Keep the distinction between a per-address, per-account and shared provider budget when interpreting a refusal.

## Rate limiting

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
| `metadata_provider_user` | `GET /api/metadata/search/*` when it searches Discogs or Last.fm (10 per minute for both providers together) | User ID |
| `lrclib` | Every LRCLIB request, from workers and web requests alike (1 per 2 seconds) | One bucket shared by all callers |
| `discovery_endpoint_ip` | `POST /api/discovery/register`, `POST /api/discovery/pairing-code`, `POST /api/discovery/qr-payload/{serverPublicId}`, `POST /api/discovery/complete-pairing` (30 per minute, one shared bucket) | Client IP |

The last seven have fixed limits in `config/packages/framework.yaml` and no `auth.yaml` parameter.

The per-IP limits run before Baander authenticates the request. The per-account login failure and step-up limits run once the account's address or user is known, and the per-client limits once the client has authenticated, so a caller who merely names a client in `client_id` cannot use up that client's allowance.

### Login attempts and account failures

The three login limits count different things. `auth_login_ip` and `auth_login_ip_email` count every `POST /api/auth/login` from an address, DPoP nonce challenges and TOTP prompts included. They slow one address down but do not stop an attack spread over many addresses: each new address brings a fresh budget.

`auth_login_email_failures` counts only rejected passwords and TOTP codes for an email address, from every address together, and it charges an address that belongs to no account exactly as one that does, so the limit reveals nothing about which accounts exist. A nonce challenge, the TOTP prompt and a successful login cost nothing against it. Once an address reaches the limit, every login for it answers `429`, the right password included, until older failures slide out of the window.

An attacker who keeps guessing can therefore lock the real user out for as long as the attack lasts; clearing the limiter from the **Rate Limits** tab or with `app:rate-limiter:clear auth_login_email_failures` lets the user in sooner, and resets the count of every address at once.

A sign-in with TOTP costs more requests than one without it, because a failed login answer, the TOTP prompt included, carries no new DPoP nonce and the web app starts each attempt without one. With no wrong code it takes four `POST /api/auth/login` requests: a nonce challenge, the password, a second nonce challenge and the password with the code. Each wrong code adds two, so a sign-in with `n` wrong codes costs `4 + 2n` requests against `auth_login_ip` and `auth_login_ip_email`.

At the default of 10 per IP and email in 5 minutes, a user can mistype the code three times in one window.

A request over a limit gets `429 Too Many Requests` with a `Retry-After` header in seconds. The password reset account limit and the verification resend user limit are the exceptions. Over the reset account limit, the endpoint returns the same `200` response as for any other address but issues no reset token, so the response never shows whether an account exists. Over the resend user limit, the endpoint returns its usual `200` response but sends no email.

The per-IP reset and verification limits still answer `429`.

### Provider budgets

The `musicbrainz` limit never answers `429`; callers wait for their turn instead. MusicBrainz allows one request per second from an address and answers `503` to an address that sends faster. Every MusicBrainz request and Cover Art Archive cover fetch, from any worker or web process, reserves the next free turn in this shared bucket and waits for it; each turn starts at least one second after the one before it. Background work waits as long as the queue ahead of it takes.

A MusicBrainz search or lookup on the metadata search and browse routes, which any signed-in user may call, first spends one call of the user's `musicbrainz_user` allowance and answers `429` when the allowance is spent. It then waits at most 10 seconds for a turn, and answers `503 Service Unavailable` when no turn comes within those 10 seconds or when the limiter state in Redis cannot be read.

A Discogs or Last.fm search on the metadata search routes spends one call of the user's `metadata_provider_user` allowance and answers `429` when the allowance is spent, so one user cannot use up the quota of the server's Discogs token or Last.fm API key. The search routes accept a `limit` of 1 to 100 and answer `422` to any other value before calling a provider. When Discogs answers `429` with a `Retry-After` of at most 5 seconds, Baander waits that long and retries once. With a longer `Retry-After`, the request does not wait, and the search returns no results.

The `lrclib` limit never answers `429` either. Every LRCLIB request, from any worker or web process, reserves the next free turn in its shared bucket and waits for it; each turn starts at least two seconds after the one before it. The fetch of one song, a lyrics search and an apply, which only administrators may call, wait at most 10 seconds, and a queued lyrics fetch at most 30.

A request whose turn comes later is not sent: LRCLIB then counts as unavailable, as it does when the limiter state in Redis cannot be read, and the lyrics routes answer `503`. Clearing `lrclib`, from the **Rate Limits** tab or with `app:rate-limiter:clear lrclib`, resets these turns only. A pause that LRCLIB asked for with a `429`, or a `503` with a `Retry-After` header, is stored outside the limiter and ends on its own, after one hour at most; see [External APIs](../media/external-apis.md#supported-apis).

Media delivery routes are exempt from `anonymous_api` and `authenticated_api`, so playback is never throttled by the general API budget: the audio stream (`/api/stream/track`), the original-file streams (`/api/stream/video`, `/api/stream/original/track`), the HLS and DASH manifests and segments under `/api/transcode/{id}/` (`master.m3u8`, `media.m3u8`, `manifest.mpd`, `init`, `segment`), the completed-video playlists and segments under `/api/transcode/artifacts/`, subtitle playlists and segments, image files (`/api/images/{id}/file`) and album covers (`GET /api/albums/{id}/cover`). Health, readiness and metrics endpoints (`/health`, `/ready`, `/live`, `/metrics`) are outside `/api` and are never limited.

With `APP_ENV=dev`, only the `config_check`, `auth_password_reset_email`, `auth_email_verification_user`, `auth_login_email_failures`, `auth_step_up_user`, `musicbrainz`, `musicbrainz_user`, `metadata_provider_user` and `lrclib` limits apply; the others are skipped.

Both HTTP layers read `TRUSTED_PROXIES`, a comma-separated list of trusted proxy
addresses or CIDRs that defaults to empty. With that default, a direct client
cannot select a rate-limit identity by supplying `X-Forwarded-For`. Behind nginx or another proxy,
configure only its actual source addresses or deliberately restricted CIDRs and
make that proxy replace incoming forwarding headers. Otherwise clients share the
proxy's address and its anonymous budget. Do not enable broad subnet trust merely
because the service runs in Docker. See
[Request identity and proxy deployment](../../part-2-developer-guide/architecture/application-threat-model.md#request-identity-and-proxy-deployment).
