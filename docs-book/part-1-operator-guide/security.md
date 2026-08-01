# Security Guide

Guidance for operators on rotating secrets, recovering from a breach, and hardening a Baander installation.

## Rotating APP_SECRET

`APP_SECRET` is used to sign session cookies, CSRF tokens, and other Symfony security components. Rotating it invalidates all existing sessions and CSRF tokens — all users will be logged out.

### Step-by-step

1. **Generate a new secret:**

```bash
php -r 'echo bin2hex(random_bytes(32));'
```

2. **Support rotation (zero downtime):**

Symfony supports comma-separated secrets for live rotation. The first value is used for signing; the rest are used only for verification.

```env
APP_SECRET=new_secret_here,old_secret_here
```

This lets existing sessions remain valid while new sessions use the new secret.

3. **Remove the old secret after sessions expire:**

Wait for the old sessions to naturally expire (or clear the Redis session store), then remove the old value:

```env
APP_SECRET=new_secret_here
```

4. **Restart the application:**

```bash
make stop && make start
```

### When to rotate

- After any suspected secret leak
- When a developer with env access leaves the project
- As part of regular security maintenance (quarterly is reasonable)

## Rotating OAuth 2.0 Keys

OAuth keys sign JWT access tokens. Rotating them invalidates all existing access and refresh tokens — all API clients must re-authenticate.

### Using the command (recommended)

The `app:auth:rotate-secrets` command automates the full rotation:

```bash
make exec cmd="php bin/console app:auth:rotate-secrets"
```

This backs up existing keys, generates a new key pair, truncates OAuth token tables, invalidates the token cache, and outputs the new encryption key. Follow the printed instructions to add the encryption key and restart.

### Manual rotation

For zero-downtime key rotation (where existing tokens remain valid until they naturally expire), manual key replacement is required:

1. **Generate a new key pair:**

```bash
openssl genrsa -out config/secrets/oauth/private-new.key 2048
openssl rsa -in config/secrets/oauth/private-new.key -pubout > config/secrets/oauth/public-new.key
```

2. **Replace the keys:**

```bash
mv config/secrets/oauth/private.key config/secrets/oauth/private-old.key
mv config/secrets/oauth/public.key config/secrets/oauth/public-old.key
mv config/secrets/oauth/private-new.key config/secrets/oauth/private.key
mv config/secrets/oauth/public-new.key config/secrets/oauth/public.key
```

3. **Restart:**

```bash
make stop && make start
```

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

```bash
# 1. Generate new APP_SECRET
NEW_SECRET=$(php -r 'echo bin2hex(random_bytes(32));')
echo "Rotate APP_SECRET to: $NEW_SECRET"

# 2. Rotate OAuth keys (also generates new encryption key and invalidates tokens)
make exec cmd="php bin/console app:auth:rotate-secrets"

# 3. Generate new VAPID keys
make exec cmd="php bin/console app:generate-vapid-keys"

# 4. Generate new Redis password and update docker-compose.yml

# 5. Generate new database password (see section above)
```

### 3. Invalidate all sessions and tokens

```bash
# Flush Redis (clears sessions, caches, rate limiter state, pending jobs)
docker compose exec redis redis-cli -a "$REDIS_PASSWORD" FLUSHALL
```

**Warning:** This also clears pending messenger jobs (library scans, notification deliveries). Re-run any critical scans after recovery.

### 4. Force all users to re-authenticate

After rotating `APP_SECRET` and flushing Redis, all existing sessions and OAuth tokens are invalid. Users will need to log in again.

If OAuth refresh tokens are a concern, consider truncating the relevant database tables:

```sql
TRUNCATE oauth_access_tokens;
TRUNCATE oauth_refresh_tokens;
TRUNCATE oauth_auth_codes;
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
- [ ] **OAuth encryption key set** — required for multi-worker setups
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
APP_URL=https://baander.example.com
APP_DOMAIN=baander.example.com
APP_NAME=Bånder
DEFAULT_URI=https://baander.example.com

DATABASE_URL="postgresql://baander:<strong_password>@database:5432/baander?serverVersion=18&charset=utf8"

REDIS_PASSWORD=<strong_password>
REDIS_URL=redis://default:<strong_password>@redis:6379
MESSENGER_TRANSPORT_DSN=redis://default:<strong_password>@redis:6379/messages
MESSENGER_CONSUMER_NAME=${HOSTNAME:-worker}

MAILER_DSN=smtp://user:pass@smtp.example.com:587

VAPID_PUBLIC_KEY=<from app:generate-vapid-keys>
VAPID_PRIVATE_KEY=<from app:generate-vapid-keys>
```

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
