# Rotate credentials

Choose the procedure for the credential being replaced. The procedures affect different state and have different recovery conditions. Run installation commands in the shell where the [`dc` wrapper](../getting-started/installation.md) is defined. Before stopping services, follow [Background work](../workers/background-work.md); keep matching data and secrets together as described in [Backup and recovery](backup-and-recovery.md).

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
[configuration lifecycle](../configuration/README.md#where-a-change-takes-effect), then
refresh separately managed workers through [Background work](../workers/background-work.md).
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

Use [the rotation runbook](../commands/app-auth-rotate-secrets.md) to prepare and
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
   configuration; see [Redis configuration](../configuration/environment-variables.md#redis).
3. Apply the server configuration and restart clients with matching credentials.
   Check Redis connectivity, fresh authentication and worker progress before
   reopening traffic.

Changing a password does not itself erase Redis data. Restart or recreation can
lose it when the deployment has no durable Redis storage; the supplied base
Compose service declares no Redis data volume. Do not assume a restart preserves
queues. Loss of that state also loses pending deliveries and other work; rerunning
a scan cannot reconstruct every lost message. See [Storage and data](../getting-started/storage-and-data.md).

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
   [configuration lifecycle](../configuration/README.md#where-a-change-takes-effect) for the
   web service and [Background work](../workers/background-work.md) for separately managed
   workers. A Compose-injected value needs container recreation; restarting the
   existing container retains its environment.

Users will need to re-enable push notifications in their browser/client. There is no way to migrate existing subscriptions to new keys.

## Rotating Database Credentials

The pooler sequence below is derived from the credential script and has not been
drilled; [the recorded limitation](../../../ROADMAP.md#docs-book-rewrite) remains open.

Database role changes depend on who owns the existing schemas, tables and sequences.
A new role with only database-level privileges is not automatically a replacement
for that owner. Do not drop the old role after granting only database access.

Drain traffic and stop application processes and workers, retain a verified backup, and have the database administrator prepare the replacement credential with the required object privileges and ownership. Update `DATABASE_URL`, `DATABASE_CONTROL_URL` and the deployment's server-side credential configuration. The control URL connects as a second role, the application role's name with `_control` appended, which needs the replacement credential too. In the supplied Compose stack, the one-off `pooler-credentials` service derives both roles' verifiers from `DB_PASSWORD`, applies them and writes the connection pooler's auth file.

It logs in as the application role with `DB_PASSWORD`, so run it again with the replacement password only after the role accepts that password, then restart the pooler. Then restart all clients, and verify protected API access, writes and worker progress before retiring the old credential. Changing a Compose initialization password does not update a role in an already initialized PostgreSQL data directory. See [Backup and recovery](backup-and-recovery.md) and [database configuration](../configuration/environment-variables.md#database).
