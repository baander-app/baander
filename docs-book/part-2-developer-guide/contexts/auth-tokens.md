# Auth Tokens and Request Validation

Every token pair is bound to a DPoP key. This page explains issuance, rotation, revocation and the checks made on API requests. [API Authentication](../development/api-authentication.md) gives the client-facing contract.

## Token Issuance

Four endpoints issue tokens. Baander's own apps use `POST /api/auth/login`, `POST /api/auth/login/passkey`, and `POST /api/auth/refresh`; other clients use `POST /api/oauth/token`. Login dispatches `IssueTokenCommand` for the SPA client named by `auth.spa_client_id`, and first-party refresh dispatches `RefreshTokenCommand`. The token endpoint dispatches one command per grant (see [Token Endpoint](auth-oauth-server.md#token-endpoint)).

Every new token pair comes from `TokenPairIssuer`, whether password login, passkey login, the authorization code grant, or the device code grant asked for it. It stores a DPoP-bound access token and a refresh token that starts a new rotation chain, with both rows written in one transaction. A grant can pass a further write that commits or rolls back with the tokens; the code grants use it to redeem their code.

The refresh token is an opaque identifier, stored only as its SHA-256 hash; neither League encryption nor an `OAUTH_ENCRYPTION_KEY` is involved. The access token JWT carries `iss` (`auth.oauth.issuer`), the header type `at+jwt` and, as `kid`, the RFC 7638 thumbprint of the signing key, which `AccessTokenSigningKeyInterface` also gives the JWKS document; `DpopAwareBearerTokenValidator` refuses a token whose `iss` is missing or different. Login asks for no scopes, so first-party tokens carry none.

The delegated grants take their scopes from `ScopeAllowlist::grantable()`: requested scopes outside `auth.scopes.user_grants` are dropped, and a request left with none fails with `invalid_scope` when it is pushed or when the device flow starts. There is no default scope.

### Refresh rotation and replay

`RefreshTokenHandler` handles both refresh paths. It atomically consumes the old refresh token, issues the new pair in the same chain, and carries the DPoP key and client fingerprint forward.

A replay normally revokes the chain and closes the WebSocket connections opened with its access tokens, as logout does. The lost-response grace is the exception: a consumed token presented with its own key inside `auth.refresh.reuse_grace_seconds` gets a fresh pair if its replacement is still unused. The replacement is revoked. The grace has a default and maximum of 60, is measured on the database clock, and is disabled by 0.

The issuing transaction makes this decision from rows read under the row lock. Two simultaneous presentations therefore leave exactly one usable pair. A presentation with another key revokes nothing. Tokens of a revoked client cannot be refreshed.

A chain also ends at its absolute lifetime, `auth.refresh.max_chain_lifetime` (7,776,000 seconds, 90 days, by default). That lifetime starts at sign-in; the newest refresh token's sliding expiry does not extend it.

`POST /api/auth/refresh` passes the first-party client into `RefreshTokenCommand`. The handler's client check therefore refuses another client's refresh token there with `401`. A server fault, such as a database restart, gets `503` with `Retry-After`; it says nothing about the token.

### Revocation and live connections

Logout (`POST /api/auth/logout`) dispatches `RevokeTokenCommand` for the access token that authenticated the request, with its chain. The handler passes the chain to `TokenChainRevoker`, which the replayed-code and revocation paths share: it revokes the refresh rows, lists the chain's access token ids under those row locks, revokes the access rows, commits, and asks the live-connections port to close every WebSocket opened with an access token of the chain.

The list is taken under the locks so that a replacement a concurrent rotation has just committed is closed with the rest. A revocation that did not commit answers `503` with `Retry-After` rather than `200`; a close the web server could not finish is logged with the chain id and the connection count, never a token id, and the request still answers `200`, because the chain is gone and `RevokedSocketReconciler` closes the connection on its next run.

Only an unknown, foreign or already revoked token answers `200` without effect.

`RevokedSocketReconciler` (Auth Infrastructure, Swoole) is the backstop for every socket: a timer in HTTP worker 0 lists the distinct access token ids of the registered connections (`WebSocketConnectionRegistry::getConnectedAccessTokenIds()`), asks `AccessTokenRepositoryInterface::findTokenIdsOfLiveChains()` which of them belong to a chain that still has a stored, unrevoked and unexpired access token on the database clock, in chunks of 500, and closes the connections of the rest in-process through the live-connections port. The chain is the unit because a rotation revokes the access token a socket was opened with while the session goes on; rotation alone never closes a socket. It follows the health monitor's lifecycle (worker 0 only, pooled services released per tick, no overlap, skipped while draining) and implements the Shared port `WorkerTimerInterface`, through which `DrainWorkerExitSubscriber` stops every tagged timer without naming a context. Its logs count dead tokens and never carry a token id.

The binding listener (`DpopBindingListener`) lets the two revocation routes through a DPoP store outage: `DpopProofValidator::validate()` takes a flag that turns the replay store's failure into a `replayUnchecked` result after every other check ran, and the listener logs one warning with the route, the thumbprint and the user id. Every other route keeps the 503.

The first-party endpoints wrap `accessToken`, `tokenType` (`DPoP`), `expiresIn`, and `refreshToken` in `data`; login responses also include `user`. The token endpoint answers in the RFC 6749 format instead: a flat object with `access_token`, `token_type` (`DPoP`), `expires_in`, `refresh_token`, and `scope`, built by `OAuthTokenResource`.

## DPoP

All four endpoints require a `DPoP` header carrying a proof (RFC 9449) with a server-issued nonce. A proof without a valid nonce gets `400` with `{"error": "use_dpop_nonce"}` and a `DPoP-Nonce` header; the client retries with a new proof that carries that nonce. Each nonce works once, and `DpopNonceManager` hands one out only after Redis stored it. A successful response returns a fresh `DPoP-Nonce` for the client's next proof; at the first-party endpoints the next nonce is stored after the tokens are committed, and when that store fails the header is left out and the client's next refresh gets one challenge. Ordinary API responses carry no nonce. At the first-party endpoints a missing `DPoP` header returns a `400` `ApiError`, and an invalid proof gets the `use_dpop_nonce` answer.

At the token endpoint, `TokenEndpointDpopListener` checks the proof before any grant runs, through `DpopTokenRequestVerifier::inspect()`, and stores a valid one on the request as `VerifiedDpopProof`. A rejected proof comes back as a `DpopProofRejection`, which the listener answers in RFC 9449 terms: `400` `use_dpop_nonce` when the proof lacks a fresh nonce, and `400` `invalid_dpop_proof` when the proof is missing or invalid, each with `error_description`, a `DPoP-Nonce` header, `Cache-Control: no-store` and `Pragma: no-cache`. Because a polling device spends a nonce on every request, every answer the controller gives, OAuth errors included, carries the next `DPoP-Nonce`; only the `503` below carries none. The listener checks a proof sent to `POST /api/oauth/par` the same way, but a push without a `DPoP` header passes, since it may bind its key with `dpop_jkt` instead (see [Pushed Authorization Requests](auth-oauth-server.md#pushed-authorization-requests)).

Tokens are bound to the proof key: the access token JWT carries `cnf.jkt`, and the thumbprint is stored with the token (`dpop_jkt`). Refresh requires a proof signed by the same key, and the new pair keeps the binding. `DpopBindingListener` requires a matching proof on every API request made with the token, except signed stream delivery URLs, where the proof is not read and no `jti` is recorded. `OAuth2Authenticator` refuses a DPoP-bound token presented with the `Bearer` scheme on every route the `api` firewall authenticates (including `POST /api/ws/tickets`, which issues the single-use ticket that `/api/ws?ticket=...` redeems through `WsTicketAuthenticator`). Replay protection records each proof's `jti` through `DpopJtiCacheInterface::isReplay()`, one atomic set-if-absent command with an expiry one second longer than the proof acceptance window (the larger of `auth.dpop.nonce_ttl` and twice `auth.dpop.clock_skew`), because times are checked in whole seconds.

`DpopProofValidator` compares a proof's `htu` with the deployment's public origins followed by the request path, never with the scheme and `Host` the request arrived with: the origin of `APP_URL` and each origin in `auth.dpop.extra_origins` (`AUTH_DPOP_EXTRA_ORIGINS`, comma-separated, empty by default). The origins are normalized once when the service is built, and one that is not an absolute `http` or `https` URL fails container boot. The test environment lists `https://baander.app` and `http://localhost`. The shipped production nginx and the preset gateway pass the upstream proxy's `X-Forwarded-Proto` on and send their own scheme only when none arrived.

#### DPoP store outage

A nonce or jti that cannot be checked is not a verdict on the proof. `DpopNonceManager` and `RedisDpopJtiCache` translate `\RedisException` and pool exhaustion into `DpopStoreUnavailableException` (Application) and log the failure; `RedisClientFactory` in Shared keeps its own exceptions. Five edges catch it and answer `503` with `Retry-After: 5`, `Cache-Control: no-store`, no `DPoP-Nonce` and no `WWW-Authenticate`: `AuthController::refresh`, `PasswordAuthenticator` (through Symfony's service-unavailable exception), `PasskeyAuthenticator`, `TokenEndpointDpopListener` and `DpopBindingListener` (the listeners through `DpopStoreUnavailableResponse`); on the logout and revoke routes `DpopBindingListener` instead accepts a proof whose replay check could not run, as described under the revocation routes above.

`OAuthController::token` and `OAuthController::par` answer a server fault the same way by throwing Symfony's service-unavailable exception, whose headers `ExceptionSubscriber` keeps. The client assertion `jti` store and a `jwks_uri` key set that cannot be fetched raise the same exception, so the pushed request and token endpoints answer them with `503` and `Retry-After` too. A genuine nonce miss keeps its `400` `use_dpop_nonce` answer and a genuine replay its `400` or `403`.

Every Redis connection has a 1 second connect and read timeout (`RedisClientFactory::TIMEOUT_SECONDS` and the DSNs in `config/packages/cache.yaml`), so a hung Redis is answered inside the web client's 10 second attempt. The health monitor alerts once per outage; the edges log nothing more.

## Client Fingerprint Binding

Password login, passkey login, and every grant at the token endpoint accept an optional `X-Baander-Client-Fingerprint` header. When present, it is stored in `oauth_token_metadata` and binds the access token: `OAuth2Authenticator` rejects any request with that token whose header is missing or different (`401`, `AUTH_INVALID_TOKEN`). If the metadata lookup fails, the request is rejected. Refresh copies the binding to the new token. Tokens issued without the header are unbound and ignore it. No first-party client sends the header today; `POST /api/ws/tickets` runs under the `api` firewall (`OAuth2Authenticator` + `DpopBindingListener`), so client fingerprint checks apply before a WebSocket ticket is issued, and `/api/ws?ticket=...` redeems the single-use ticket through `WsTicketAuthenticator`.

## Scope Enforcement

`OAuth2Authenticator` sets three request attributes from the access token it accepts, named in `AccessTokenAttributes`: the client's public ID, a first-party marker and the token's scopes. A token is first-party only when its client is the client registered under `auth.spa_client_id` with `ClientType::FirstParty`; a personal access client, a `first_party` client under another ID and every other client are delegated. A delegated token authenticates its user with `ROLE_USER` alone, so admin checks in voters and controllers deny it, and a first-party token carries an empty scope list. A request without the first-party marker counts as delegated with no scopes.

`ScopeEnforcementListener` runs on the `api` firewall's login success, before access control, so a delegated token on an admin route is refused for its scope rather than its role. A first-party token passes. Any other authenticated request reaches only the routes `ScopeRouteMap` lists as public or maps to one of its scopes, and is otherwise answered `403` with `{"error": {"message": ..., "code": "insufficient_scope"}}` and `WWW-Authenticate: DPoP error="insufficient_scope"` (RFC 6750 section 3.1); neither access control nor the controller runs.

`ScopeRouteMap` names every route in exactly one of three lists: public routes, first-party routes, and scoped routes with the scopes that reach them (`profile` and `email` for `GET /api/auth/me`; `library` for browsing, streaming, playback, transcoding, devices, favorites, history, recommendations and radio; `playlist` for playlists). `ScopeRouteInventoryTest` loads the routes without compiling the container and fails on an unclassified route, a stale or duplicate entry, an unknown scope, or a public or scoped route that requires an admin role.

`GET /api/auth/me` answers a delegated token through `UserResource::forDelegatedToken()`, without `roles`, `totpEnabled` and `pendingEmail`, and without `email` and `emailVerifiedAt` unless the token holds `email`. `LibraryReadScopeProvider::forUser()` uses the request token's admin authority when the request is the user's own, so an administrator's delegated `library` token cannot reach other libraries through delivery decisions or playback.
