# Respond to exposed credentials

Use this procedure to contain an exposure, preserve evidence and verify access before reopening traffic. Credential-specific cutovers are in [Rotate credentials](credential-rotation.md); the [security guide](security.md) covers the installation review.

## Breach Recovery

Drain external traffic and stop affected application processes before restoring
trust. Preserve logs, configuration and data for investigation in a protected
location. Use [Backup and recovery](backup-and-recovery.md) to establish which
state can be restored; do not reset the installation as an investigation step.

### 1. Identify what was exposed

| Exposure | State to investigate |
|----------|----------------------|
| Application environment or secret provider | Database/Redis credentials, OAuth key paths and key access, APP_SECRET, stream URL secret, TOTP encryption key, VAPID and external-provider credentials |
| PostgreSQL | Accounts and password hashes, encrypted TOTP secrets, OAuth grants and codes (refresh tokens only as hashes), token metadata, notification data, encrypted webhook secrets and push subscriptions |
| Redis | Framework sessions, rate limits, DPoP state, handshake tickets, caches and pending work |
| Application host or process | All credentials it can read, running processes, open connections and source media permissions |

### 2. Contain the affected accounts and credentials

Disable suspicious accounts using [app:user:disable](../commands/app-user-disable.md)
from the web container. This revokes their access and refresh tokens and requests
closure of their sockets. Audit user creation and assigned roles through the admin
Users page or [app:user:list](../commands/app-user-list.md); remove unauthorized
privileges only after preserving the evidence needed for investigation.

For exposure affecting all OAuth grants or signing keys, follow the
[offline OAuth rotation](credential-rotation.md#rotating-oauth-20-keys). Keep issuers, resource servers
and workers stopped until invalidation, token-cache cleanup and configuration
installation are confirmed. Restarting the web server ends sockets opened before
the cutover. Restoring old signing keys does not restore deleted grants.

Do not use `FLUSHALL` as session revocation. It deletes all Redis databases,
including queues and rate-limit state, while PostgreSQL OAuth grants remain.
The supported OAuth invalidation command deletes the related grant/code tables
transactionally and clears the token cache without a blanket Redis flush.

### 3. Rotate each exposed secret with its own recovery conditions

- Follow [APP_SECRET rotation](credential-rotation.md#rotating-app_secret) with delivery workers stopped
  until every stored webhook secret and receiver has been rekeyed. Keep the
  database and matching old value together for recovery.
- Replace exposed database and Redis credentials using [their rotation procedures](credential-rotation.md).
  Preserve pending work before a Redis service change.
- Replace `STREAM_URL_HMAC_SECRET` and restart the web server if signed video
  URLs must end globally. With the preset, change it in the configuration's
  `environment` object and run the preset's `start` action, as in
  [Rotate credentials](credential-rotation.md); it recreates web and the worker
  pair with the new value. OAuth revocation does not end those URLs; see
  [Disabled accounts](../administration/sessions-and-access.md#disabled-accounts).
- Replacing an exposed `AUTH_TOTP_ENCRYPTION_KEY` locks out every user who has TOTP on.
  Baander does not re-encrypt stored secrets, so under a new key those users' logins
  answer `AUTH_TOTP_UNAVAILABLE` until an operator resets their
  TOTP with [app:user:reset-totp](../commands/app-user-reset-totp.md); they then sign
  in with their password and set TOTP up again. Whoever holds both the database and
  the old key can read the old secrets.
- Rotate exposed webhook receiver credentials, external-provider credentials and
  VAPID keys. VAPID rotation requires users to subscribe again; a webhook signing
  secret is independent of the receiver URL's own credentials.

### 4. Verify before reopening traffic

Review application and proxy access logs without publishing tokens or secret-bearing
URLs. From the installed checkout, in the shell where the installation's
[`dc` function](../getting-started/installation.md#command-context) is defined,
application logs can be inspected with:

```bash
dc logs --since 24h web
```

Confirm fresh authentication, refusal of the accounts/grants you disabled or
invalidated, protected API access for an authorized account, component health and
worker progress. Verify webhook signatures at receivers and check for missing or
duplicate work after any Redis loss. Confirm the affected host and software are
trusted before resuming traffic. The [hardening checklist](security.md#hardening-checklist)
is a configuration review, not proof that an attacker has been removed.
