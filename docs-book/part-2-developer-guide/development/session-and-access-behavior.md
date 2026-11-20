# Session and access behavior

This reference describes token binding, access checks, browser logout and Redis-outage
behavior. Operator actions and media-access limits are in
[Sessions and access](../../part-1-operator-guide/administration/sessions-and-access.md).
For the Auth implementation, see [token lifecycle](../contexts/auth-tokens.md) and
[the OAuth server](../contexts/auth-oauth-server.md); [API authentication](api-authentication.md)
explains how clients send requests.

## OAuth clients and cross-origin access

Besides Baander's own apps, other clients can obtain tokens through the OAuth 2.0 authorization server: the authorization code grant with mandatory S256 PKCE, the device authorization grant for devices without a browser, and personal access clients that users create for their own tools. Administrators register device, public and confidential clients with the `app:oauth:client:*` commands or the admin API; see [OAuth Clients](../../part-1-operator-guide/administration/oauth-clients.md#oauth-clients).

### Token binding

Every access token and refresh token, from any grant, is bound to the DPoP key that requested it. API requests with the token must carry a proof signed by that key, except signed stream delivery URLs, and refresh requires the same key. A WebSocket upgrade accepts a single-use ticket issued through the authenticated, DPoP-protected `POST /api/ws/tickets` endpoint. The ticket is bound to its user, access token, exact Origin and `/api/ws`, expires within thirty seconds and no later than its access token.

Redemption consumes it atomically and checks current token/user state. A raw access token cannot authenticate an upgrade. An unused stolen ticket can still race its owner; see the residual limits in the [threat model](../architecture/application-threat-model.md#open-findings-and-qualification-work). A token request that sends `X-Baander-Client-Fingerprint` also binds the access token to that fingerprint.

Refresh tokens rotate, and reusing one revokes its whole chain, with one exception: a consumed token presented again with its own key within `auth.refresh.reuse_grace_seconds`, while the pair it was exchanged for is still unused, gets a fresh pair and that unused pair is revoked, so a client whose refresh answer was lost keeps its session. Baander stores refresh tokens only as SHA-256 hashes, and every chain ends 90 days after its sign-in by default (`auth.refresh.max_chain_lifetime`), however often it was refreshed.

See [Token lifetimes](../../part-1-operator-guide/configuration/authentication-and-limits.md#token-lifetimes).

### Delegated tokens

Only tokens of the first-party client, the one `AUTH_SPA_CLIENT_ID`
names, carry the user's full authority. Every other client's tokens count the user as a
plain user, whatever roles the account has, and reach only the routes their scopes cover;
any other route answers `403` `insufficient_scope`. No scope reaches administration or
account security. See [Delegated tokens and scopes](../../part-1-operator-guide/configuration/authentication-and-limits.md#delegated-tokens-and-scopes).

### DPoP origins

A proof is valid only for the URL it names, and Baander compares that
URL with `APP_URL` and `AUTH_DPOP_EXTRA_ORIGINS`, not with the scheme and `Host` the
request arrived with, which a proxy or the client can change.

### Client secrets

A confidential client's secret is shown once, when it is generated, and stored only as a SHA-256 digest. Rotating it ends the old secret at once and leaves issued tokens valid.

### Client keys

A key a client withdraws from the set it publishes at its `jwks_uri` keeps working until Baander's cached copy expires, up to `auth.client_jwks.cache_ttl` (one hour by default). To end a leaked key at once, replace the client's keys, with the same URL if it is unchanged; see [Client assertions and key sets](../../part-1-operator-guide/configuration/authentication-and-limits.md#client-assertions-and-key-sets).

### Cross-origin access

The pushed authorization request endpoint, the token endpoint and the device authorization endpoint accept requests from any origin, because their callers prove possession with DPoP instead of cookies. Revocation, device approval, the authorization endpoint `/api/oauth/authorize`, and all other API routes except the discovery routes under `/api/discovery/` accept only the `APP_URL` origin; the discovery routes accept any origin and still apply their own authentication.

Third-party clients do not call the authorization endpoint; they push the request to `POST /api/oauth/par` and send the user's browser to the web app's consent page, which calls it for the signed-in user. See [HTTP](../../part-1-operator-guide/configuration/environment-variables.md#http).

### Rate limits

The authorization, pushed authorization request, token, and device authorization endpoints each have a per-IP limiter. The pushed authorization request and token endpoints also count requests per client, charged only after the client has authenticated; a public client, which has no secret to prove, is counted per client and caller network. User code lookups and approvals share a per-IP limit that bounds code guessing. See [Rate limiting](../../part-1-operator-guide/configuration/rate-limits.md#rate-limiting).

### Revocation

Revoking a client also revokes every access and refresh token issued to it. This holds for an administrator revoking a device, public or confidential client and for a user revoking a personal access client. Deleting a user deletes their tokens, authorization codes, and device codes.


## Who can change the catalog, and who can share a playlist

Every change to the catalog and the libraries is administrator-only: creating, editing, merging and deleting albums, songs, movies and genres, uploading and removing covers, extracting covers, deleting with file removal, creating, editing, deleting and scanning libraries and validating a library path. A member or an unrelated user who sends such a request answers `403` from the role check, and the request changes nothing: no row, no file, no queued job.

Reads stay scoped by library membership: a filter naming an artist, album, genre or song of a library the member cannot read answers an empty page.

Playlists are personal. The owner alone edits a playlist's details, deletes it and names collaborators by their public id; a collaborator may view it and, while the owner keeps collaboration switched on, change its songs, adding only songs their own library membership lets them see; a collaborator may leave. An administrator who does not own the playlist may view it and nothing more.

No code path acts as an administrator when it has no signed-in user: a request without an identity sees no library, the voters refuse a missing user, and the only library lookup that ran unrestricted by default now names its reason at each caller. Two integration suites, `CatalogMutationFirewallTest` and `PlaylistMutationFirewallTest`, prove these rules through the production firewall, and a unit guard fails when a new write route under `/api/` has no rows in them.


## Sessions, logout and a Redis outage

A session is a chain of token pairs: the pair a login issued and every pair a refresh rotated from it.

### Logout

`POST /api/auth/logout` revokes the whole chain of the access token that authenticated the request, and the web server closes every WebSocket connection that was opened with any access token of that chain. A refresh with any token of the chain afterwards answers `401`. Rotation alone never closes a socket. When the chain could not be revoked, the request answers `503` with `Retry-After: 5` instead of `200`: the tokens are still live, and the same logout sent again revokes them.

A connection the web server could not close at that moment is closed by the socket reconciliation below. The web app signs out every tab of the account at once: the other tabs close their session socket and show the login page without calling the server.

### The web app keeps a pending logout

The web app ends the session in the browser before the server answers and keeps the logout as a pending entry, with the key that signs it, until the server confirms it: it sends the entry again when the app starts, when the browser comes back online, when the tab becomes visible, and on a timer that backs off.

When the access token has expired it refreshes first, with the entry's own refresh token, and the entry ends when the logout answers `200` or when that refresh is refused, so a logout lost on a bad network, during an outage or while offline still reaches the server.

Until then a logged-out browser still holds the ended session's refresh token and signing key in its storage; anyone who can run script on the origin in that browser can use them, which is why the entry is deleted the moment the server confirms. At most 20 entries are kept.

### Socket reconciliation

Once a minute, the web server lists the access tokens behind its open WebSocket connections and closes every connection whose token chain is dead: every access token of the chain is revoked or expired, or the chain is no longer stored, whatever ended it. That covers a logout whose close failed, a password change, a client revocation, a user disabled from a console process in another container, and a session whose tokens expired without a refresh.

The chain is the unit, not the token the socket was opened with: a refresh revokes that token while the session goes on, and rotation alone never closes a socket. A dead chain therefore keeps its sockets for at most one minute.

### `POST /api/oauth/revoke`

Revokes a token of the signed-in user together with its chain and sockets, like logout. It takes an access token, found by its ID, or a refresh token, and only Baander's own apps can call it. It answers a token of another user and an unknown token exactly alike (`200`, nothing revoked), so it cannot be used to find out whether a token exists.

### Reuse of a refresh token

A consumed refresh token presented outside the lost-response grace (see the token binding rule under [OAuth clients and cross-origin access](../../part-1-operator-guide/administration/sessions-and-access.md#oauth-clients-and-cross-origin-access)) is treated as stolen: the chain is revoked and the web server closes its WebSocket connections, exactly as a logout does, before the `401` is answered.

### A Redis outage

Rate-limit state and the DPoP nonce/replay stores live in Redis. Requests that require unavailable state answer `503` with `Cache-Control: no-store`: rate-limit failures carry `Retry-After: 1`, while DPoP store failures carry `Retry-After: 5`. The first rejecting check determines the response. A DPoP store failure carries no `DPoP-Nonce` or `WWW-Authenticate` header and makes no decision that the token is invalid. A refresh refused for unavailable state neither consumes nor revokes its token; the same token can refresh once Redis answers.

The web app keeps its session and retries after the delay. Each Redis connection has one-second connect and read timeouts, but a request can make several Redis calls, so this is not a one-second total request deadline. See [Redis](../../part-1-operator-guide/configuration/environment-variables.md#redis). The health monitor sends administrators one alert per outage; see [Monitoring](../../part-1-operator-guide/operations/health-and-metrics.md#what-is-checked).

### Logout during a Redis outage

`POST /api/auth/logout` and `POST /api/oauth/revoke` retain narrow availability exceptions. The generic API limiter permits these routes when its state is unavailable, but still enforces a confirmed exhausted budget. If the DPoP replay store cannot answer, the server still verifies the proof's signature, method and URL, age, access-token hash and key binding, and skips only the replay check. No other authentication or proof check is bypassed.

A replayed logout proof can therefore only end the session its access token belongs to; after a successful logout, the revoked token is refused before any proof is read. A replayed revoke can also end another chain of the same user whose token ID the replayer already holds, since the proof does not cover the request body. Skipping the replay check logs one warning with the route, key thumbprint and user, never a token. Replay-store pool exhaustion takes that same path.

An authorized logout revokes the chain in PostgreSQL and answers `200` during the outage.
