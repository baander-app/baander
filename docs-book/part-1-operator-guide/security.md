# Security Guide

Choose the operation by what was exposed. Disabling one account, revoking OAuth
grants, replacing signing keys and ending signed media URLs affect different
state. Clearing Redis does not revoke the OAuth grants stored in PostgreSQL.

Run commands on the installation host, from its checkout, with the
[`dc` wrapper](getting-started.md). Before planned downtime, follow
[Background work](background-work.md) to stop or drain separately managed workers.
Keep matching data and secrets together as described in
[Backup and recovery](backup-and-recovery.md).

The [application threat model](../part-2-developer-guide/application-threat-model.md)
records trust boundaries, regression evidence and unresolved findings.

## Rotating APP_SECRET

`APP_SECRET` is one literal value used by Symfony security components and to
encrypt stored webhook signing secrets. It is not the OAuth signing key, and
changing it does not revoke PostgreSQL OAuth grants. Symfony session/CSRF state is
separate from the token-pair sessions used by the API.

Webhook delivery cannot decrypt its stored secrets until each webhook is rotated
under the new value or the matching old value is restored. Comma-separated values
are not a rotation mechanism in this application.

### Step-by-step

1. **Prepare recovery and stop delivery.** Back up the database together with the current `APP_SECRET`. Drain traffic and stop web and worker instances so no webhook is delivered during the change. Keep the old value available for recovery.

2. **Generate a new secret:**

```bash
php -r 'echo bin2hex(random_bytes(32));'
```

3. **Install one new value on every instance.** Keep delivery workers stopped. Start
   one web instance to perform webhook administration, keeping ordinary traffic
   drained. Authenticate as an administrator; APP_SECRET rotation alone has not
   revoked existing API tokens.

```env
APP_SECRET=new_secret_here
```

4. **Rekey every configured webhook.** Call `POST /api/webhooks/{id}/rotate-secret` for each webhook and install its returned secret at the receiver. Each response shows the new secret only once. Keep workers stopped until all receivers are ready.

5. **Resume and verify.** Restart every instance with the same new `APP_SECRET`, resume worker delivery, and check fresh authentication and webhook verification.

Apply the new environment to the web service using the
[configuration lifecycle](configuration.md#where-a-change-takes-effect), then
refresh separately managed workers through [Background work](background-work.md).
If values are injected by Compose, recreate the container with the new values;
`docker compose restart` retains the old container environment.

If rekeying fails, keep workers stopped. Restore the database backup and its matching old `APP_SECRET` together, then restore receiver secrets changed during this attempt before resuming delivery. Restoring only the old value cannot decrypt webhook rows already rekeyed under the new value. If the old value is unavailable, rotate affected webhooks under the new value and update their receivers before starting workers.

### When to rotate

- After any suspected secret leak
- When a developer with env access leaves the project
- When your installation's credential policy requires a change

## Rotating OAuth 2.0 Keys

OAuth keys sign JWT access tokens. The supported offline rotation also deletes
access and refresh tokens, authorization codes, device codes and token metadata
from PostgreSQL. All API clients must authenticate again. Replacing a key alone
is not the same operation as revoking stored grants.

### Staged offline rotation

Use [the rotation runbook](commands/app-auth-rotate-secrets.md) to prepare and
validate a protected bundle without touching active secrets. Drain traffic and
stop every issuer, resource server, and background worker before running
`invalidate --offline`. This flag is an operator assertion, not an automatic fence.

The command deletes OAuth grants and metadata in one PostgreSQL transaction, then
clears the token cache. Keep all instances stopped through retries and installation
of the bundle's two `OAUTH_*` configuration values. Restart and verify fresh
authentication before resuming traffic. Never retry invalidation after resuming.

The command retains active files unchanged and prints no new secret. Preserve the
old configuration for startup recovery; restoring keys cannot restore deleted
grants. This procedure requires downtime. Replacing the signing key alone does
not provide a zero-downtime transition for existing tokens.

## Rotating Redis Password

Redis holds framework sessions, rate-limit and DPoP state, caches and queued work.
API access and refresh grants are stored in PostgreSQL. Changing a Redis password
is a connectivity cutover, not a token-revocation operation.

1. Drain traffic and stop all application processes and background workers that
   connect to Redis. Preserve queued work using the
   [backup procedure](backup-and-recovery.md) before changing the Redis service.
2. Update the Redis server credential and every client reference together:
   `REDIS_PASSWORD`, `REDIS_URL`, `MESSENGER_TRANSPORT_DSN` and any explicit
   deployment overrides. Keep the values in the installation's protected
   configuration; see [Redis configuration](configuration.md#redis).
3. Apply the server configuration and restart clients with matching credentials.
   Check Redis connectivity, fresh authentication and worker progress before
   reopening traffic.

Changing a password does not itself erase Redis data. Restart or recreation can
lose it when the deployment has no durable Redis storage; the supplied base
Compose service declares no Redis data volume. Do not assume a restart preserves
queues. Loss of that state also loses pending deliveries and other work; rerunning
a scan cannot reconstruct every lost message. See [Storage and data](storage-and-data.md).

## Rotating VAPID Keys

Rotating VAPID keys invalidates all existing push subscriptions. Browsers must re-subscribe.

### Step-by-step

1. **Generate new keys:**

```bash
dc exec app php bin/console app:generate-vapid-keys
```

2. **Update the protected web configuration and worker credentials** with the new
   public and private keys.

3. **Apply the environment and refresh delivery.** Follow the
   [configuration lifecycle](configuration.md#where-a-change-takes-effect) for the
   web service and [Background work](background-work.md) for separately managed
   workers. A Compose-injected value needs container recreation; restarting the
   existing container retains its environment.

Users will need to re-enable push notifications in their browser/client. There is no way to migrate existing subscriptions to new keys.

## Rotating Database Credentials

Database role changes depend on who owns the existing schemas, tables and sequences.
A new role with only database-level privileges is not automatically a replacement
for that owner. Do not drop the old role after granting only database access.

Drain traffic and stop application processes and workers, retain a verified backup,
and have the database administrator prepare the replacement credential with the
required object privileges and ownership. Update `DATABASE_URL` and the deployment's
server-side credential configuration, restart all clients, and verify protected API
access, writes and worker progress before retiring the old credential. Changing a
Compose initialization password does not update a role in an already initialized
PostgreSQL data directory. See [Backup and recovery](backup-and-recovery.md) and
[database configuration](configuration.md#database).

## Breach Recovery

Drain external traffic and stop affected application processes before restoring
trust. Preserve logs, configuration and data for investigation in a protected
location. Use [Backup and recovery](backup-and-recovery.md) to establish which
state can be restored; do not reset the installation as an investigation step.

### 1. Identify what was exposed

| Exposure | State to investigate |
|----------|----------------------|
| Application environment or secret provider | Database/Redis credentials, OAuth key paths and key access, APP_SECRET, stream URL secret, VAPID and external-provider credentials |
| PostgreSQL | Accounts and password hashes, OAuth grants and codes, token metadata, notification data, encrypted webhook secrets and push subscriptions |
| Redis | Framework sessions, rate limits, DPoP state, handshake tickets, caches and pending work |
| Application host or process | All credentials it can read, running processes, open connections and source media permissions |

### 2. Contain the affected accounts and credentials

Disable suspicious accounts using [app:user:disable](commands/app-user-disable.md)
from the web container. This revokes their access and refresh tokens and requests
closure of their sockets. Audit user creation and assigned roles through the admin
Users page or [app:user:list](commands/app-user-list.md); remove unauthorized
privileges only after preserving the evidence needed for investigation.

For exposure affecting all OAuth grants or signing keys, follow the
[offline OAuth rotation](#rotating-oauth-20-keys). Keep issuers, resource servers
and workers stopped until invalidation, token-cache cleanup and configuration
installation are confirmed. Restarting the web server ends sockets opened before
the cutover. Restoring old signing keys does not restore deleted grants.

Do not use `FLUSHALL` as session revocation. It deletes all Redis databases,
including queues and rate-limit state, while PostgreSQL OAuth grants remain.
The supported OAuth invalidation command deletes the related grant/code tables
transactionally and clears the token cache without a blanket Redis flush.

### 3. Rotate each exposed secret with its own recovery conditions

- Follow [APP_SECRET rotation](#rotating-app_secret) with delivery workers stopped
  until every stored webhook secret and receiver has been rekeyed. Keep the
  database and matching old value together for recovery.
- Replace exposed database and Redis credentials using the procedures above.
  Preserve pending work before a Redis service change.
- Replace `STREAM_URL_HMAC_SECRET` and restart the web server if signed video
  URLs must end globally. OAuth revocation does not end those URLs; see
  [Disabled accounts](#disabled-accounts).
- Rotate exposed webhook receiver credentials, external-provider credentials and
  VAPID keys. VAPID rotation requires users to subscribe again; a webhook signing
  secret is independent of the receiver URL's own credentials.

### 4. Verify before reopening traffic

Review application and proxy access logs without publishing tokens or secret-bearing
URLs. From the installed checkout, application logs can be inspected with:

```bash
dc logs --since 24h app
```

Confirm fresh authentication, refusal of the accounts/grants you disabled or
invalidated, protected API access for an authorized account, component health and
worker progress. Verify webhook signatures at receivers and check for missing or
duplicate work after any Redis loss. Confirm the affected host and software are
trusted before resuming traffic. The [hardening checklist](#hardening-checklist)
is a configuration review, not proof that an attacker has been removed.

## Securing the Installation

### Hardening checklist

- [ ] **Unique `APP_SECRET`** — never use the default `change_me_in_production`
- [ ] **Strong Redis password** — not the default `baander`
- [ ] **Strong database password** — not the default `baander`
- [ ] **`APP_ENV=prod`** in production — disables debug mode, verbose errors, and the profiler
- [ ] **HTTPS in production** — set `DEFAULT_URI` and `APP_URL` to `https://`
- [ ] **Reverse proxy configured** — set `TRUSTED_PROXIES` to the actual trusted proxy addresses or CIDRs. Both HTTP layers default to no trust. Proxies must replace incoming `X-Forwarded-*` headers; direct access leaves the list empty.
- [ ] **Rate limiting active** — relies on Redis; verify Redis is reachable
- [ ] **External API keys secured** — store in `.env`, never in source code or config committed to git
- [ ] **OAuth keys not in version control** — `config/secrets/oauth/` should be in `.gitignore`
- [ ] **Database not exposed** — PostgreSQL should only be accessible from the internal Docker network, not from the host

### Production `.env` template

```env
APP_ENV=prod
APP_SECRET=<generate with: php -r 'echo bin2hex(random_bytes(32));'>
APP_URL=https://baander.app
APP_DOMAIN=baander.app
DEFAULT_URI=https://baander.app

DATABASE_URL="postgresql://baander:<strong_password>@database:5432/baander?serverVersion=18&charset=utf8"

REDIS_PASSWORD=<strong_password>
REDIS_URL=redis://default:<strong_password>@redis:6379
MESSENGER_TRANSPORT_DSN=redis://default:<strong_password>@redis:6379/messages
MESSENGER_CONSUMER_NAME=${HOSTNAME:-worker}

MAILER_DSN=smtp://user:pass@smtp.baander.app:587

VAPID_PUBLIC_KEY=<from app:generate-vapid-keys>
VAPID_PRIVATE_KEY=<from app:generate-vapid-keys>
```

### OAuth clients and cross-origin access

Besides Baander's own apps, other clients can obtain tokens through the OAuth 2.0 authorization server: the authorization code grant with mandatory S256 PKCE, the device authorization grant for devices without a browser, and personal access clients that users create for their own tools. Administrators register device, public and confidential clients with the `app:oauth:client:*` commands or the admin API; see [OAuth Clients](user-management.md#oauth-clients).

- **Token binding.** Every access token and refresh token, from any grant, is bound to the
  DPoP key that requested it. API requests with the token must carry a proof signed by that
  key, except signed stream delivery URLs, and refresh requires the same key. A WebSocket
  upgrade accepts a single-use ticket issued through the authenticated, DPoP-protected
  `POST /api/ws/tickets` endpoint. The ticket is bound to its user, access token, exact Origin and
  `/api/ws`, expires within thirty seconds and no later than its access token. Redemption
  consumes it atomically and checks current token/user state. A raw access token cannot
  authenticate an upgrade. An unused stolen ticket can still race its owner; see the residual
  limits in the [threat
  model](../part-2-developer-guide/application-threat-model.md#open-findings-and-qualification-work).
  A token request that sends `X-Baander-Client-Fingerprint` also binds the access token to
  that fingerprint. Refresh tokens rotate, and reusing one revokes its whole chain, with one
  exception: a consumed token presented again with its own key within
  `auth.refresh.reuse_grace_seconds`, while the pair it was exchanged for is still unused,
  gets a fresh pair and that unused pair is revoked, so a client whose refresh answer was lost
  keeps its session. See [Token lifetimes](configuration.md#token-lifetimes).
- **Client secrets.** A confidential client's secret is shown once, when it is generated, and stored only as a SHA-256 digest. Rotating it ends the old secret at once and leaves issued tokens valid.
- **Cross-origin access.** The token endpoint and the device authorization endpoint accept requests from any origin, because their callers prove possession with DPoP instead of cookies. Revocation, device approval, the authorization endpoint `/api/oauth/authorize`, and all other API routes accept only the `APP_URL` origin. Third-party clients do not call the authorization endpoint; they send the user's browser to the web app's consent page, which calls it for the signed-in user. See [HTTP](configuration.md#http).
- **Rate limits.** The authorization, token, and device authorization endpoints each have a per-IP limiter, and the token endpoint also counts requests per client. User code lookups and approvals share a per-IP limit that bounds code guessing. See [Rate limiting](configuration.md#rate-limiting).
- **Revocation.** Revoking a client also revokes every access and refresh token issued to it. This holds for an administrator revoking a device, public or confidential client and for a user revoking a personal access client. Deleting a user deletes their tokens, authorization codes, and device codes.

### Disabled accounts

Disabling a user, from the admin panel or with [app:user:disable](commands/app-user-disable.md), revokes the user's access and refresh tokens in the transaction that saves the disable. Every authenticator then refuses the account: API requests, token refresh and new WebSocket handshakes. After the commit, the web server closes the user's open WebSocket connections on every worker. The command reaches the server through its control socket, so run it in the web container. In a container without a web server, it disables the account and logs a warning that it could not reach the open connections; the socket reconciliation (see [Sessions, logout and a Redis outage](#sessions-logout-and-a-redis-outage)) closes them within a minute.

Two kinds of media access outlive the disable:

- **A response already being sent.** A track file, or a transcode that is still encoding, keeps streaming until that one response ends. Every later request authenticates again and is refused.
- **Signed video stream URLs.** HLS and DASH URLs carry an HMAC signature, not the user's token, so they keep working until they expire. A signed manifest URL lives at most 24 hours. Each manifest the player fetches signs the URLs it lists for another 24 hours. A player that keeps fetching can therefore stream that one video for up to 72 hours after the URL was signed with HLS, and up to 48 hours with DASH. To end every signed URL at once, for every user, set a new `STREAM_URL_HMAC_SECRET` and restart the web server. A signed URL is a bearer capability: it serves whoever holds it, with no session, until it expires, and a member of another library or a signed-out browser is served alike. A request that does present a token on a signed URL must present a valid one: an expired, revoked, foreign-audience or malformed token, or a fingerprint-bound token without its header, answers `401` even with a valid signature, because the firewall validates any token it is shown before the signature is read. The DPoP proof is not read on delivery routes, so no replay key is recorded there. The signature value is redacted from every log record, the access log included, so a logged URL cannot be replayed. A DPoP-bound token presented with the `Bearer` scheme instead of `DPoP` answers `401` on every route the API firewall authenticates; WebSocket upgrades accept only a single-use handshake ticket, not an access token.

### Who can change the catalog, and who can share a playlist

Every change to the catalog and the libraries is administrator-only: creating, editing, merging and deleting albums, songs, movies and genres, uploading and removing covers, extracting covers, deleting with file removal, creating, editing, deleting and scanning libraries and validating a library path. A member or an unrelated user who sends such a request answers `403` from the role check, and the request changes nothing: no row, no file, no queued job. Reads stay scoped by library membership: a filter naming an artist, album, genre or song of a library the member cannot read answers an empty page.

Playlists are personal. The owner alone edits a playlist's details, deletes it and names collaborators by their public id; a collaborator may view it and, while the owner keeps collaboration switched on, change its songs, adding only songs their own library membership lets them see; a collaborator may leave. An administrator who does not own the playlist may view it and nothing more.

No code path acts as an administrator when it has no signed-in user: a request without an identity sees no library, the voters refuse a missing user, and the only library lookup that ran unrestricted by default now names its reason at each caller. Two integration suites, `CatalogMutationFirewallTest` and `PlaylistMutationFirewallTest`, prove these rules through the production firewall, and a unit guard fails when a new write route under `/api/` has no rows in them.

### Sessions, logout and a Redis outage

A session is a chain of token pairs: the pair a login issued and every pair a refresh rotated from it.

- **Logout.** `POST /api/auth/logout` revokes the whole chain of the access token that authenticated the request, and the web server closes every WebSocket connection that was opened with any access token of that chain. A refresh with any token of the chain afterwards answers `401`. Rotation alone never closes a socket. When the chain could not be revoked, the request answers `503` with `Retry-After: 5` instead of `200`: the tokens are still live, and the same logout sent again revokes them. A connection the web server could not close at that moment is closed by the socket reconciliation below. The web app signs out every tab of the account at once: the other tabs close their session socket and show the login page without calling the server.
- **The web app keeps a pending logout.** The web app ends the session in the browser before the server answers and keeps the logout as a pending entry, with the key that signs it, until the server confirms it: it sends the entry again when the app starts, when the browser comes back online, when the tab becomes visible, and on a timer that backs off. When the access token has expired it refreshes first, with the entry's own refresh token, and the entry ends when the logout answers `200` or when that refresh is refused, so a logout lost on a bad network, during an outage or while offline still reaches the server. Until then a logged-out browser still holds the ended session's refresh token and signing key in its storage; anyone who can run script on the origin in that browser can use them, which is why the entry is deleted the moment the server confirms. At most 20 entries are kept.
- **Socket reconciliation.** Once a minute, the web server lists the access tokens behind its open WebSocket connections and closes every connection whose token chain is dead: every access token of the chain is revoked or expired, or the chain is no longer stored, whatever ended it. That covers a logout whose close failed, a password change, a client revocation, a user disabled from a console process in another container, and a session whose tokens expired without a refresh. The chain is the unit, not the token the socket was opened with: a refresh revokes that token while the session goes on, and rotation alone never closes a socket. A dead chain therefore keeps its sockets for at most one minute.
- **`POST /api/oauth/revoke`.** Revokes a token of the signed-in user together with its chain and sockets, like logout. It answers a token of another user and an unknown token exactly alike (`200`, nothing revoked), so it cannot be used to find out whether a token exists.
- **Reuse of a refresh token.** A consumed refresh token presented outside the lost-response grace (see the token binding rule under [OAuth clients and cross-origin access](#oauth-clients-and-cross-origin-access)) is treated as stolen: the chain is revoked and the web server closes its WebSocket connections, exactly as a logout does, before the `401` is answered.
- **A Redis outage.** Rate-limit state and the DPoP nonce/replay stores live in
  Redis. Requests that require unavailable state answer `503` with
  `Cache-Control: no-store`: rate-limit failures carry `Retry-After: 1`, while
  DPoP store failures carry `Retry-After: 5`. The first rejecting check determines
  the response. A DPoP store failure carries no `DPoP-Nonce` or `WWW-Authenticate`
  header and makes no decision that the token is invalid. A refresh refused for
  unavailable state neither consumes nor revokes its token; the same token can
  refresh once Redis answers. The web app keeps its session and retries after the
  delay. Each Redis connection has one-second connect and read timeouts, but a
  request can make several Redis calls, so this is not a one-second total request
  deadline. See [Redis](configuration.md#redis). The health monitor sends
  administrators one alert per outage; see [Monitoring](monitoring.md#what-is-checked).
- **Logout during a Redis outage.** `POST /api/auth/logout` and
  `POST /api/oauth/revoke` retain narrow availability exceptions. The generic API
  limiter permits these routes when its state is unavailable, but still enforces a
  confirmed exhausted budget. If the DPoP replay store cannot answer, the server
  still verifies the proof's signature, method and URL, age, access-token hash and
  key binding, and skips only the replay check. No other authentication or proof
  check is bypassed. A replayed logout proof can therefore only end the session its
  access token belongs to; after a successful logout, the revoked token is refused
  before any proof is read. A replayed revoke can also end another chain of the same
  user whose token ID the replayer already holds, since the proof does not cover the
  request body. Skipping the replay check logs one warning with the route, key
  thumbprint and user, never a token. Replay-store pool exhaustion takes that same
  path. An authorized logout revokes the chain in PostgreSQL and answers `200`
  during the outage.

### Network security

The supplied Compose topology places Nginx in front of the web server. Inspect the
actual published ports before exposing the installation:

- **Port 80/443** — Nginx handles TLS termination and proxies to the Swoole server
- **Port 9200** — Swoole management API. It has no authentication and can report status, reload workers and shut the server down, so it listens only on `127.0.0.1` inside the app container and no compose file publishes it. `swoole:server:status` and `swoole:server:reload` reach it from inside the container. Keep `swoole.http_server.api.host` on loopback; the bundle also defaults to `127.0.0.1` when no host is configured.
- **Port 5432** — the base Compose file publishes PostgreSQL on the host; remove or
  restrict this publication for an exposed installation.
- **Port 6379** — the base Compose file binds Redis on host loopback. Keep it
  inaccessible to untrusted clients.

The base file also binds the web service on host loopback port 9501 and publishes
UDP port 41234. Review every publication and host firewall rule for your deployment;
[Getting started](getting-started.md) describes the source-install topology.

### Outbound provider requests

Metadata lookups, Spotify token acquisition, IPRD catalog downloads and browser-push
delivery use approved HTTPS providers on port 443. Each request validates the host
and every DNS answer, rejects non-public destinations, and pins one approved
address while preserving hostname verification. Proxies and automatic redirects
are disabled, and each request uses a fresh connection. No LAN exception applies.

Cover Art Archive is the only redirect exception: it may follow at most three GET
redirects within `coverartarchive.org`, `archive.org` or their label-boundary
subdomains. Every hop repeats the destination checks and drops credentials and
cookies when crossing origins. Other metadata and IPRD redirects are refused.

Push delivery revalidates endpoints already stored in subscriptions, not just new
subscription input. A 3xx is a failed delivery, while 404/410 retain the existing
expired-subscription cleanup. Minishlink still encrypts the payload and signs VAPID;
the transport does not automatically replay push requests. Delivery logs omit
provider API-key query strings, endpoint credentials and raw transport failures.

DNS resolution and HTTP share one monotonic request budget, capped at thirty
seconds, across a CAA redirect chain. Push delivery likewise spends one
thirty-second budget on DNS and sending, with connection time capped at five
seconds or the remaining budget. A fresh PHP helper performs the complete native
A/AAAA lookup while the calling coroutine waits without blocking its worker.
Timeout, cancellation, invalid replies and exhausted helper capacity stop delivery.
Cleanup and operating-system scheduling can extend the observed return time;
existing provider retries can also extend the overall operation. These controls
do not promise a hard real-time or whole-operation deadline.
Webhooks, operator-configured lyrics, mail, tracing and browser playback have
separate destination policies. The [application threat model](../part-2-developer-guide/application-threat-model.md#provider-transport-boundaries)
records the scope and qualification limits.

### File permissions

- OAuth keys (`config/secrets/oauth/`) should be readable only by the app process
- `.env` files should not be world-readable
- Media storage (`/storage/media`) should be writable only by the app process

### Password security

Passwords are hashed with Argon2id (memory cost: 65536, time cost: 4). This is a strong default. Only increase these values if you have specific compliance requirements and sufficient server memory.

If an attacker obtains the database, they cannot reverse hashed passwords — but they can attempt to crack weak ones. Encourage users to use strong passwords (the minimum is 8 characters, enforced by the create-user command).
