# Security Guide

Guidance for operators on rotating secrets, recovering from a breach, and hardening a Baander installation.

## Rotating APP_SECRET

`APP_SECRET` is one literal value used by Symfony security components and to encrypt stored webhook signing secrets. Changing it invalidates existing sessions and CSRF tokens. Webhook delivery cannot decrypt its stored secrets until each webhook is rotated under the new value or the matching old value is restored. Comma-separated values are not a rotation mechanism in this application.

### Step-by-step

1. **Prepare recovery and stop delivery.** Back up the database together with the current `APP_SECRET`. Drain traffic and stop web and worker instances so no webhook is delivered during the change. Keep the old value available for recovery.

2. **Generate a new secret:**

```bash
php -r 'echo bin2hex(random_bytes(32));'
```

3. **Install one new value on every instance.** Keep workers stopped. Start one web instance and sign in as an administrator with a new session.

```env
APP_SECRET=new_secret_here
```

4. **Rekey every configured webhook.** Call `POST /api/webhooks/{id}/rotate-secret` for each webhook and install its returned secret at the receiver. Each response shows the new secret only once. Keep workers stopped until all receivers are ready.

5. **Resume and verify.** Restart every instance with the same new `APP_SECRET`, resume worker delivery, and check fresh authentication and webhook verification.

```bash
make stop && make start
```

If rekeying fails, keep workers stopped. Restore the database backup and its matching old `APP_SECRET` together, then restore receiver secrets changed during this attempt before resuming delivery. Restoring only the old value cannot decrypt webhook rows already rekeyed under the new value. If the old value is unavailable, rotate affected webhooks under the new value and update their receivers before starting workers.

### When to rotate

- After any suspected secret leak
- When a developer with env access leaves the project
- As part of regular security maintenance (quarterly is reasonable)

## Rotating OAuth 2.0 Keys

OAuth keys sign JWT access tokens. Rotating them invalidates all existing access and refresh tokens — all API clients must re-authenticate.

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

Redis stores sessions, rate limiter state, messenger jobs, and cache tags. Rotating the password requires updating all references simultaneously.

### Step-by-step

1. **Update `.env`:**

```env
REDIS_PASSWORD=new_password_here
```

2. **Update `docker-compose.yml`** (the Redis service and any services that pass it as a variable):

```yaml
redis:
  command: redis-server --maxmemory-policy noeviction --requirepass new_password_here
  environment:
    REDIS_PASSWORD: new_password_here
```

3. **Restart everything at once:**

```bash
docker compose down && docker compose up -d
```

The `FLUSHDB` approach doesn't work here because you can't authenticate with the new password against the old Redis instance. A full restart is required.

**Consequence:** All sessions are lost (users logged out), all cached data is cleared, and pending messenger jobs are lost.

## Rotating VAPID Keys

Rotating VAPID keys invalidates all existing push subscriptions. Browsers must re-subscribe.

### Step-by-step

1. **Generate new keys:**

```bash
make exec cmd="php bin/console app:generate-vapid-keys"
```

2. **Update `.env`** with the new public and private keys.

3. **Restart the application:**

```bash
make stop && make start
```

Users will need to re-enable push notifications in their browser/client. There is no way to migrate existing subscriptions to new keys.

## Rotating Database Credentials

1. **Create a new database user and grant access:**

```sql
CREATE USER baander_new WITH PASSWORD 'new_password';
GRANT ALL PRIVILEGES ON DATABASE baander TO baander_new;
```

2. **Update `DATABASE_URL` in `.env`:**

```env
DATABASE_URL="postgresql://baander_new:new_password@database:5432/baander?serverVersion=18&charset=utf8"
```

3. **Restart the application:**

```bash
make stop && make start
```

4. **Drop the old user** once the application is confirmed running:

```sql
DROP USER baander;
```

## Breach Recovery

If you suspect or confirm that the installation has been breached, follow these steps in order.

### 1. Identify what was exposed

Check which secrets an attacker may have accessed:

| Access level | Exposed secrets |
|-------------|----------------|
| `.env` file read | All secrets — this is the worst case |
| Database access | User passwords (hashed), email addresses, OAuth tokens, push subscriptions |
| Redis access | Active sessions, rate limiter state, pending jobs |
| Source code read | No secrets directly, but reveals architecture |

### 2. Rotate all secrets

There is no shortcut — rotate everything:

First follow the [offline `APP_SECRET` procedure](#rotating-app_secret), keeping
workers stopped through webhook rekeying. Then follow the staged OAuth rotation
runbook linked above; keep every instance stopped through invalidation and
configuration replacement. Rotate the remaining secrets as needed:

```bash
# Generate new VAPID keys
make exec cmd="php bin/console app:generate-vapid-keys"

# Generate new Redis password and update docker-compose.yml

# Generate new database password (see section above)
```

### 3. Invalidate all sessions and tokens

```bash
# Flush Redis (clears sessions, caches, rate limiter state, pending jobs)
docker compose exec redis redis-cli -a "$REDIS_PASSWORD" FLUSHALL
```

**Warning:** This also clears pending messenger jobs (library scans, notification deliveries). Re-run any critical scans after recovery.

### 4. Force all users to re-authenticate

After rotating `APP_SECRET` and flushing Redis, all existing sessions and OAuth tokens are invalid. Users will need to log in again.

If OAuth refresh tokens are a concern, truncate the token tables in one statement. Refresh tokens and token metadata reference access tokens, so PostgreSQL rejects truncating `oauth_access_tokens` on its own. Include the authorization code and device code tables: an unredeemed code can still be exchanged for a new token pair.

```sql
TRUNCATE oauth_access_tokens, oauth_refresh_tokens, oauth_token_metadata, oauth_auth_codes, oauth_device_codes;
```

### 5. Audit user accounts

Check for accounts that may have been created or modified during the breach:

```sql
-- Users created in the last 24 hours
SELECT id, email, name, created_at
FROM users
WHERE created_at > NOW() - INTERVAL '24 hours'
ORDER BY created_at DESC;

-- Users with admin roles
SELECT id, email, name
FROM users
WHERE roles::jsonb ? 'ROLE_ADMIN';
```

Revoke admin privileges from any suspicious accounts and consider locking down user registration (`auth.rate_limit.register.max_attempts`).

### 6. Review access logs

Check for suspicious API activity:

```bash
# Check Docker logs for unusual patterns
docker compose logs app --since 24h | grep -i "auth\|login\|password"
```

### 7. Harden before going live

After recovery, review the [hardening checklist](#hardening-checklist) before bringing the instance back online.

## Securing the Installation

### Hardening checklist

- [ ] **Unique `APP_SECRET`** — never use the default `change_me_in_production`
- [ ] **Strong Redis password** — not the default `baander`
- [ ] **Strong database password** — not the default `baander`
- [ ] **`APP_ENV=prod`** in production — disables debug mode, verbose errors, and the profiler
- [ ] **HTTPS in production** — set `DEFAULT_URI` and `APP_URL` to `https://`
- [ ] **Reverse proxy configured** — Nginx terminates TLS and sets `X-Forwarded-*` headers (already configured in `swoole.yaml` with `trusted_proxies: ['*']`)
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
APP_NAME=Bånder
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

Besides Baander's own apps, other clients can obtain tokens through the OAuth 2.0 authorization server: the authorization code grant with mandatory S256 PKCE, the device authorization grant for devices without a browser, and personal access clients that users create for their own tools. No command or admin page registers third-party or device clients yet.

- **Token binding.** Every access token and refresh token, from any grant, is bound to the DPoP key that requested it. API requests with the token must carry a proof signed by that key, except signed stream delivery URLs, and refresh requires the same key. A token request that sends `X-Baander-Client-Fingerprint` also binds the access token to that fingerprint. Refresh tokens rotate, and reusing one revokes its whole chain.
- **Cross-origin access.** The token endpoint and the device authorization endpoint accept requests from any origin, because their callers prove possession with DPoP instead of cookies. The authorization endpoint refuses every cross-origin request (RFC 9700 section 2.6). Revocation, device approval, and all other API routes accept only the `APP_URL` origin. See [HTTP](configuration.md#http).
- **Rate limits.** The authorization, token, and device authorization endpoints each have a per-IP limiter, and the token endpoint also counts requests per client. User code lookups and approvals share a per-IP limit that bounds code guessing. See [Rate limiting](configuration.md#rate-limiting).
- **Revocation.** A user who revokes a personal access client also revokes every access and refresh token issued to it. Deleting a user deletes their tokens, authorization codes, and device codes.

### Network security

Baander runs behind Nginx in Docker Compose. By default:

- **Port 80/443** — Nginx handles TLS termination and proxies to the Swoole server
- **Port 9200** — Swoole API server (should NOT be exposed to the internet in production)
- **Port 5432** — PostgreSQL (should NOT be exposed — Docker internal network only)
- **Port 6379** — Redis (should NOT be exposed — Docker internal network only)

Review your `docker-compose.yml` and ensure only the Nginx ports are published to the host.

### File permissions

- OAuth keys (`config/secrets/oauth/`) should be readable only by the app process
- `.env` files should not be world-readable
- Media storage (`/storage/media`) should be writable only by the app process

### Password security

Passwords are hashed with Argon2id (memory cost: 65536, time cost: 4). This is a strong default. Only increase these values if you have specific compliance requirements and sufficient server memory.

If an attacker obtains the database, they cannot reverse hashed passwords — but they can attempt to crack weak ones. Encourage users to use strong passwords (the minimum is 8 characters, enforced by the create-user command).
