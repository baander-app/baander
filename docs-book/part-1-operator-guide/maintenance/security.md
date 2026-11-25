# Security Guide

Choose the operation by what was exposed. Disabling one account, revoking OAuth
grants, replacing signing keys and ending signed media URLs affect different
state. Clearing Redis does not revoke the OAuth grants stored in PostgreSQL.

Run commands on the installation host, from its checkout, with the
[`dc` function](../getting-started/installation.md#command-context). Before planned downtime, follow
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
- [ ] **Strong Redis password** — at least 16 bytes and not the default `baander`. With `APP_ENV=prod`, every process refuses to boot while `REDIS_PASSWORD` is unset, `baander` or shorter than 16 bytes, or while the password in `REDIS_URL` is missing, `baander` or shorter than 16 bytes.
- [ ] **Strong database password** — at least 16 bytes and not the default `baander`. With `APP_ENV=prod`, every process refuses to boot while the password in `DATABASE_URL`, or in `DATABASE_CONTROL_URL` when it is set, is empty, `baander` or shorter than 16 bytes.
- [ ] **Own Web Push keys** — generate the VAPID pair with `app:generate-vapid-keys`. With `APP_ENV=prod`, every process refuses to boot with a `VAPID_PRIVATE_KEY` the repository has ever committed, because anyone can read those keys from its history.
- [ ] **`APP_ENV=prod`** in production — disables debug mode, verbose errors, and the profiler. Baander refuses to boot under any environment name other than `dev`, `test` and `prod`.
- [ ] **HTTPS in production** — set `DEFAULT_URI` and `APP_URL` to `https://`
- [ ] **Reverse proxy configured** — set `TRUSTED_PROXIES` to the actual trusted proxy addresses or CIDRs. Both HTTP layers default to no trust. Proxies must replace incoming `X-Forwarded-*` headers; direct access leaves the list empty. The [deployment preset](../../../deploy/preset/README.md) derives `TRUSTED_PROXIES` to its own gateway and refuses a configured value; list a proxy in front of the gateway under `listen.frontProxies` instead.
- [ ] **Rate limiting active** — relies on Redis; verify Redis is reachable
- [ ] **External API keys secured** — store in the private configuration's `environment` object, never in source code or config committed to git
- [ ] **OAuth keys not in version control** — `config/secrets/oauth/` should be in `.gitignore`
- [ ] **Database not exposed** — PostgreSQL should only be accessible from the internal Docker network, not from the host

### Production configuration

The [deployment preset](../../../deploy/preset/README.md) runs web and the worker
pair with `APP_ENV=prod` and `APP_DEBUG=0`. Put the installation's own values in the
`environment` object of its private configuration, kept readable only by the
operator:

```json
"origin": "https://baander.app",
"environment": {
    "APP_SECRET": "<generate with: php -r 'echo bin2hex(random_bytes(32));'>",
    "STREAM_URL_HMAC_SECRET": "<generate with: php -r 'echo bin2hex(random_bytes(32));'>",
    "AUTH_TOTP_ENCRYPTION_KEY": "<generate with: openssl rand -base64 32>",
    "DB_USERNAME": "baander",
    "DB_PASSWORD": "<strong_password>",
    "DB_DATABASE": "baander",
    "REDIS_PASSWORD": "<strong_password>",
    "MAILER_DSN": "smtp://user:pass@smtp.baander.app:587",
    "VAPID_PUBLIC_KEY": "<from app:generate-vapid-keys>",
    "VAPID_PRIVATE_KEY": "<from app:generate-vapid-keys>"
}
```

The preset refuses the settings it controls in `environment`: `APP_ENV` and
`APP_DEBUG`; `APP_URL`, `DEFAULT_URI` and `APP_DOMAIN`, derived from `origin`;
`DATABASE_URL` and `DATABASE_CONTROL_URL` from the three `DB_*` values; `REDIS_URL`
and `MESSENGER_TRANSPORT_DSN` from `REDIS_PASSWORD`; the storage paths, `APP_HOME`,
the OAuth key paths and `BAANDER_DEPLOYMENT_PROFILE`; and `TRUSTED_PROXIES`, which
it sets to its gateway. Rendering and starting refuse any value that still begins
with `replace-with-`, and they apply the boot rules before any container starts: a
secret shorter than 32 bytes, a password shorter than 16 bytes, and every secret or
password the repository commits, the values in `.env.test` included, are refused. The
containers themselves still accept the `.env.test` values, because the test suites boot
production kernels on them. The preset initializes PostgreSQL, gives the database roles
and the connection pooler's auth file their verifiers, and builds both database
URLs, all from the one `DB_PASSWORD`.

### Network security

The deployment preset places its gateway, an nginx container, in front of the web
server and publishes no other port. Inspect the actual published ports before
exposing the installation:

- **`listen.port` on `listen.address`** — the gateway proxies plain HTTP to the Swoole server; the example configurations listen on `127.0.0.1:8080`. The gateway does not terminate TLS: for an HTTPS origin, run a TLS reverse proxy in front of it and declare that proxy in `listen.frontProxies`, as [Gateway and client addresses](../../../deploy/preset/README.md#gateway-and-client-addresses) describes
- **Port 9200** — Swoole management API. It has no authentication and can report status, reload workers and shut the server down, so it listens only on `127.0.0.1` inside the web container and no compose file publishes it. `swoole:server:status` and `swoole:server:reload` reach it from inside the container. Keep `swoole.http_server.api.host` on loopback; the bundle also defaults to `127.0.0.1` when no host is configured.
- **Ports 5432, 6432 and 6379** — the preset publishes none of PostgreSQL, the
  connection pooler and Redis on the host. They share the deployment's private
  network with web and the worker pair; reach them with `dc exec`. Keep them
  inaccessible to untrusted clients.

The preset does not publish web's port 9501 either, where the web server answers
`/metrics` and the health probes without authentication. The gateway answers
`/metrics` with `404`. Review every publication and host firewall rule for your
deployment; [Installation](../getting-started/installation.md) describes the
installation's topology.

### Browser protections

Every response carries `X-Content-Type-Options: nosniff` and `X-Frame-Options: DENY`, static files included. The static file server does not serve a `.php` file or a path with a segment that starts with a dot, except `.well-known`; such a request goes to the application instead. The web app's HTML page also carries a Content-Security-Policy. Scripts run only from the installation's own origin or with a nonce issued for that page view. Other sites cannot frame the page (`frame-ancestors 'none'`), plugins and `<base>` elements are blocked, and forms post only to the installation's own origin (`form-action 'self'`). Images and media may come from any HTTPS host, because radio streams and station logos live on the stations' own servers. The policy admits plain-HTTP stations only when the page itself is served over HTTP, because on a page served over HTTPS they would be mixed content.

The policy's `connect-src` allows a WebSocket only to the public origin the page names as its API URL: the request's origin when it is `APP_URL`'s or listed in `AUTH_EXTRA_ORIGINS`, otherwise `APP_URL`'s. It also allows `APP_URL`'s origin, where signed media URLs point. The WebSocket handshake itself still accepts only `APP_URL`'s origin, so a page served on an origin listed in `AUTH_EXTRA_ORIGINS` loads, but its WebSocket upgrade is refused. A `Host` header, forged by the client or forwarded by a trusted proxy, never chooses an origin the server renders. Baander sets no `trusted_hosts` list, because internal callers reach the web server under Hosts such as `gateway`, `web:9501` and `127.0.0.1:9501`, which such a list would answer with `400`.

Stored image files (`/api/images/{id}/file`) are served with the media type Baander recorded for them when it is JPEG, PNG, WebP or GIF, and converted derivatives as WebP. Any other recorded type is served as an `application/octet-stream` download. Each image file response also carries `Content-Security-Policy: default-src 'none'; sandbox`, so an uploaded file cannot run script at the installation's origin.

Library media is protected the same way. An original video or track (`/api/stream/video`, `/api/stream/original/track` and `/api/stream/track`) is served with the media type of its probed container and file extension, never a type read from the file's bytes; a format that Baander does not list as a media container goes out as `application/octet-stream`. These responses also carry `Content-Disposition: attachment` and `Content-Security-Policy: default-src 'none'; sandbox`, so a signed media link opened in a browser tab downloads the file instead of playing it there. Playback inside Baander is unchanged. Any other response that is not the web app's HTML page carries `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox`, unless its route sets its own policy.

A request that reaches Baander as HTTPS, directly or through a proxy listed in `TRUSTED_PROXIES` that sets `X-Forwarded-Proto: https`, also gets `Strict-Transport-Security: max-age=31536000`. With an untrusted proxy, Baander sees plain HTTP and sends no HSTS.

### File permissions

- OAuth keys (`config/secrets/oauth/`) should be readable only by the app process
- `.env` files should not be world-readable
- Media storage (`/storage/media`) should be writable only by the app process

### Password security

Passwords are hashed with Argon2id (memory cost: 65536, time cost: 4). Increase these values only if you have specific compliance requirements and sufficient server memory.

If an attacker obtains the database, they cannot reverse hashed passwords — but they can attempt to crack weak ones. Encourage users to use strong passwords (the minimum is 8 characters, enforced by the create-user command).
