# Security Guide

Choose the operation by what was exposed. Disabling one account, revoking OAuth
grants, replacing signing keys and ending signed media URLs affect different
state. Clearing Redis does not revoke the OAuth grants stored in PostgreSQL.

Run commands on the installation host, from its checkout, with the
[`dc` wrapper](../getting-started/installation.md). Before planned downtime, follow
[Background work](../workers/background-work.md) to stop or drain separately managed workers.
Keep matching data and secrets together as described in
[Backup and recovery](backup-and-recovery.md).

The [application threat model](../../part-2-developer-guide/architecture/application-threat-model.md)
records trust boundaries, regression evidence and unresolved findings.

## Choose a security task

- [Rotate credentials](credential-rotation.md): APP_SECRET, OAuth signing keys, Redis, VAPID and database credentials. Each procedure states what must stop and what recovery requires.
- [Respond to an exposure](incident-response.md): identify affected state, contain access and verify the installation before reopening traffic.
- [Check sessions and access](../administration/sessions-and-access.md): account disable, logout, signed media and Redis-outage behavior.
- [Check outbound provider security](outbound-provider-security.md): approved destinations, redirects and request budgets.

## Securing the Installation

### Hardening checklist

- [ ] **Private secrets** — set `APP_SECRET`, `STREAM_URL_HMAC_SECRET` and `AUTH_TOTP_ENCRYPTION_KEY` to private random values. With `APP_ENV=prod`, every web, worker and console process refuses to boot while one of them is unset, still the value committed in `.env`, a `replace-with-` placeholder or shorter than 32 bytes. For `AUTH_TOTP_ENCRYPTION_KEY`, the 32 bytes are counted after base64 decoding. The error names the variable.
- [ ] **Strong Redis password** — not the default `baander`. With `APP_ENV=prod`, every process refuses to boot while `REDIS_PASSWORD` is unset or `baander`.
- [ ] **Strong database password** — not the default `baander`. With `APP_ENV=prod`, every process refuses to boot while the password in `DATABASE_URL`, or in `DATABASE_CONTROL_URL` when it is set, is empty or `baander`.
- [ ] **Own Web Push keys** — generate the VAPID pair with `app:generate-vapid-keys`. With `APP_ENV=prod`, every process refuses to boot with a `VAPID_PRIVATE_KEY` the repository has ever committed, because anyone can read those keys from its history.
- [ ] **`APP_ENV=prod`** in production — disables debug mode, verbose errors, and the profiler
- [ ] **HTTPS in production** — set `DEFAULT_URI` and `APP_URL` to `https://`
- [ ] **Reverse proxy configured** — set `TRUSTED_PROXIES` to the actual trusted proxy addresses or CIDRs. Both HTTP layers default to no trust. Proxies must replace incoming `X-Forwarded-*` headers; direct access leaves the list empty. The [deployment preset](../../../deploy/preset/README.md) derives `TRUSTED_PROXIES` to its own gateway and refuses a configured value; list a proxy in front of the gateway under `listen.frontProxies` instead.
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

DB_PASSWORD=<strong_password>
DATABASE_URL="postgresql://baander:<strong_password>@pooler:6432/baander?serverVersion=18&charset=utf8"
DATABASE_CONTROL_URL="postgresql://baander_control:<strong_password>@pooler:6432/baander_control?serverVersion=18&charset=utf8"

REDIS_PASSWORD=<strong_password>
REDIS_URL=redis://default:<strong_password>@redis:6379
MESSENGER_TRANSPORT_DSN=redis://default:<strong_password>@redis:6379/messages
MESSENGER_CONSUMER_NAME=${HOSTNAME:-worker}

MAILER_DSN=smtp://user:pass@smtp.baander.app:587

VAPID_PUBLIC_KEY=<from app:generate-vapid-keys>
VAPID_PRIVATE_KEY=<from app:generate-vapid-keys>

STREAM_URL_HMAC_SECRET=<generate with: php -r 'echo bin2hex(random_bytes(32));'>
AUTH_TOTP_ENCRYPTION_KEY=<generate with: openssl rand -base64 32>
```

The supplied Compose stack initializes PostgreSQL and writes the connection pooler's
verifiers from `DB_PASSWORD`, which defaults to `baander` when unset. Use the same
password in `DB_PASSWORD`, `DATABASE_URL` and `DATABASE_CONTROL_URL`.

### Network security

The supplied Compose topology places Nginx in front of the web server. Inspect the
actual published ports before exposing the installation:

- **Port 80/443** — Nginx proxies to the Swoole server. The base Compose file builds its development configuration, which also terminates TLS on 443 with a self-signed certificate; the production configuration listens on port 80 only and expects a reverse proxy in front of it to terminate TLS
- **Port 9200** — Swoole management API. It has no authentication and can report status, reload workers and shut the server down, so it listens only on `127.0.0.1` inside the app container and no compose file publishes it. `swoole:server:status` and `swoole:server:reload` reach it from inside the container. Keep `swoole.http_server.api.host` on loopback; the bundle also defaults to `127.0.0.1` when no host is configured.
- **Port 5432** — the base Compose file publishes PostgreSQL on the host; remove or
  restrict this publication for an exposed installation.
- **Port 6379** — the base Compose file binds Redis on host loopback. Keep it
  inaccessible to untrusted clients.

The base file also binds the web service on host loopback port 9501, where the
web server answers `/metrics` and the health probes without authentication. The
production Nginx configuration answers `/metrics` with `404`. Review every
publication and host firewall rule for your deployment;
[Getting started](../getting-started/installation.md) describes the source-install topology.

### Browser protections

Every response carries `X-Content-Type-Options: nosniff` and `X-Frame-Options: DENY`, static files included. The web app's HTML page also carries a Content-Security-Policy. Scripts run only from the installation's own origin or with a nonce issued for that page view. Other sites cannot frame the page (`frame-ancestors 'none'`), and plugins and `<base>` elements are blocked. Images and media may come from any HTTP(S) host, because radio streams and station logos live on the stations' own servers.

The policy's `connect-src` allows a WebSocket only to the public origin the page names as its API URL: the request's origin when it is `APP_URL`'s or listed in `AUTH_EXTRA_ORIGINS`, otherwise `APP_URL`'s. It also allows `APP_URL`'s origin, where signed media URLs point. The WebSocket handshake itself still accepts only `APP_URL`'s origin, so a page served on an origin listed in `AUTH_EXTRA_ORIGINS` loads, but its WebSocket upgrade is refused. A `Host` header, forged by the client or forwarded by a trusted proxy, never chooses an origin the server renders. Baander sets no `trusted_hosts` list, because internal callers reach the web server under Hosts such as `gateway`, `web:9501` and `127.0.0.1:9501`, which such a list would answer with `400`.

Stored image files (`/api/images/{id}/file`) are served with the media type Baander recorded for them when it is JPEG, PNG, WebP or GIF, and converted derivatives as WebP. Any other recorded type is served as an `application/octet-stream` download. Each image file response also carries `Content-Security-Policy: default-src 'none'; sandbox`, so an uploaded file cannot run script at the installation's origin.

A request that reaches Baander as HTTPS, directly or through a proxy listed in `TRUSTED_PROXIES` that sets `X-Forwarded-Proto: https`, also gets `Strict-Transport-Security: max-age=31536000`. With an untrusted proxy, Baander sees plain HTTP and sends no HSTS.

### File permissions

- OAuth keys (`config/secrets/oauth/`) should be readable only by the app process
- `.env` files should not be world-readable
- Media storage (`/storage/media`) should be writable only by the app process

### Password security

Passwords are hashed with Argon2id (memory cost: 65536, time cost: 4). Increase these values only if you have specific compliance requirements and sufficient server memory.

If an attacker obtains the database, they cannot reverse hashed passwords — but they can attempt to crack weak ones. Encourage users to use strong passwords (the minimum is 8 characters, enforced by the create-user command).
