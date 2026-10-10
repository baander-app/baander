# Notifications

Baander supports three notification channels: browser push notifications, outgoing webhooks, and email. This page covers operator-level setup and configuration for each channel.

## Push Notifications (VAPID)

Browser push notifications use the [Web Push protocol](https://developer.mozilla.org/en-US/docs/Web/API/Push_API) with VAPID (Voluntary Application Server Identification) for server authentication. Users subscribe from their browser after you configure the keys.

### Setup

1. **Generate a VAPID key pair:**

```bash
make exec cmd="php bin/console app:generate-vapid-keys"
```

See the [app:generate-vapid-keys](commands/app-generate-vapid-keys.md) command reference for full details.

2. **Add the keys to your `.env` file:**

```env
VAPID_PUBLIC_KEY=BIj3...
VAPID_PRIVATE_KEY=MIGT...
```

Both variables are documented in [configuration.md](configuration.md#web-push-vapid).

3. **Restart the application** after setting the keys.

Users can then enable push notifications from their browser or client. If you need to rotate keys later, see the [VAPID key rotation](security.md#rotating-vapid-keys) section in the security guide.

## Webhooks

Outgoing webhooks let Baander deliver notifications to external services (Slack, Discord, custom integrations) via HTTP POST. Webhooks are admin-only — the API requires the `ROLE_ADMIN` role.

### Webhook API

All webhook endpoints live under `/api/webhooks`. Each one has a console command for operators with shell access: [app:webhook:list](commands/app-webhook-list.md), [app:webhook:create](commands/app-webhook-create.md), [app:webhook:update](commands/app-webhook-update.md), [app:webhook:delete](commands/app-webhook-delete.md) and [app:webhook:rotate-secret](commands/app-webhook-rotate-secret.md).

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/webhooks` | List all configured webhooks |
| `POST` | `/api/webhooks` | Create a new webhook |
| `PUT` | `/api/webhooks/{id}` | Update a webhook |
| `DELETE` | `/api/webhooks/{id}` | Delete a webhook |
| `POST` | `/api/webhooks/{id}/rotate-secret` | Rotate the signing secret (returned once) |

### Creating a webhook

Send a `POST` request with at minimum a `url` field:

```json
{
  "url": "https://hooks.slack.com/services/...",
  "category_filter": ["security", "media_changes"]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `url` | `string` (URI) | Yes | The endpoint to deliver notifications to. Must be a valid URL. |
| `category_filter` | `array<string>` or `null` | No | Restrict which notification categories trigger this webhook. `null` delivers all categories. |

The response includes a `secret` value on creation. This is the only time the secret is returned — store it securely.

```json
{
  "data": {
    "id": "0197d2ef-...",
    "url": "https://hooks.slack.com/services/...",
    "category_filter": ["security", "media_changes"],
    "secret": "a1b2c3d4...",
    "signing_version": 2,
    "created_at": "2026-04-25T10:00:00+00:00",
    "updated_at": "2026-04-25T10:00:00+00:00"
  }
}
```

### Updating and deleting

Use `PUT /api/webhooks/{id}` to change the `url` or `category_filter`. Omitting a field leaves it unchanged. Use `DELETE /api/webhooks/{id}` to remove a webhook entirely.

### HMAC signature verification

Each webhook delivery uses signature protocol version 2 and includes these headers:

| Header | Description |
|--------|-------------|
| `X-Baander-Webhook-Signature-Version` | Always `2` |
| `X-Baander-Webhook-Timestamp` | Unix timestamp of the delivery |
| `X-Baander-Webhook-Signature` | HMAC-SHA256 signature over `{timestamp}.{payload}` |

To verify a delivery, compute `sha256=` followed by the hexadecimal HMAC-SHA256
of the header timestamp, a literal `.`, and the exact raw request body. Use the
original secret returned at creation or rotation as the HMAC key. Compare the
complete signature with a constant-time comparison. Reject timestamps outside
your receiver's freshness window and deduplicate the `Idempotency-Key` header.
Re-encoding the JSON changes the signed bytes.

Baander stores the secret encrypted with its application secret; keep that
application secret stable and protected. Creation and rotation return the original
webhook secret once. Rotation takes effect after the database flush succeeds:
configure the receiver with the new secret before sending subsequent notifications.

Before upgrading a local database past migration `Version20261004010000`, use the
previous application to rotate any webhook whose `encrypted_secret` is absent,
and configure its receiver with the returned secret. Export or back up your local
configuration first. The migration refuses to change the schema while such rows
exist, because the original secret cannot be recovered from a hash. It preserves
existing encrypted secrets and webhook identifiers; it does not generate replacement
secrets or delete records. Fresh installations need no preparation.

### Delivery behavior

- **Retries:** Failed deliveries (server errors, timeouts) are retried up to 3 times with exponential backoff (1s, 2s, 4s). Client errors (4xx) are not retried.
- **Timeout:** Each delivery attempt times out after 10 seconds.
- **SSRF protection:** Webhook URLs are validated against a blocklist of private and loopback IP ranges. Deliveries to internal network addresses are silently dropped.
- **User-Agent:** Requests are sent with `Baander-Webhook/1.0`.

### Notification categories

Category filters control which events trigger a webhook. The available categories are:

| Category | Description |
|----------|-------------|
| `security` | Authentication events, password changes, security alerts |
| `background_jobs` | Library scan results, transcoding progress, async job status |
| `media_changes` | New media added, metadata updates, library changes |

Set `category_filter` to `null` (or omit it when creating) to receive all categories.

## Email

Email notifications are sent through Symfony's Mailer component. Configure the transport in your environment.

### Configuration

Set `MAILER_DSN` in `.env`:

```env
MAILER_DSN=smtp://user:pass@smtp.example.com:587
```

The Docker development environment includes [Mailpit](https://mailpit.axllent.org/) as a local SMTP server for testing. The default development DSN is `smtp://mailpit:1025`. See the Mail section in [configuration.md](configuration.md#mail) for full details, including the sender address.

Password reset emails use the same transport but are not notifications: they ignore notification preferences and are sent whenever a user asks for a reset. See [Password reset](configuration.md#password-reset).

### Language

Notification emails are written in each recipient's [email language](configuration.md#email-language): the user's own choice, otherwise the server default `i18n.default_language`, otherwise English. The worker looks the language up when it sends each email, so a change made after the notification was queued applies to it. In-app notifications, push notifications and webhook payloads are always in English.

## Server Settings

Two [server settings](configuration.md#server-settings) switch parts of notification delivery off for the whole server. Baander reads them for every message, so a change applies without a restart.

| Setting | Default | While it is off |
|---------|---------|-----------------|
| `notifications.push_enabled` | `true` | No browser push is sent. The notification is still created and delivered in the app and by any other channel, and each skipped push is logged. |
| `notifications.admin_alerts` | `true` | A component that turns unhealthy no longer alerts administrators; the degradation is still logged. Other admin alerts, such as new user registrations, are always sent. |

### Health alerts

The web server raises health alerts. One of its HTTP workers (worker 0) runs the [health checks](monitoring.md#what-is-checked) every 60 seconds: PostgreSQL, Redis, the background worker, the Swoole server and the memory of the server's workers. `HEALTH_MONITOR_INTERVAL_SECONDS` sets the interval. The first check runs one interval after the server starts, so services that start alongside it do not alert.

Each outage alerts once. A component alerts when its check reads unhealthy, and alerts again only after it has read healthy in between. A check that reads not available neither alerts nor ends an outage. Recovery is logged; no notification is sent for it.

The server keeps each component's alert state for as long as it runs. A component that is already unhealthy when the server starts alerts once, at the first check. A reload of the server's workers keeps the state, so it does not repeat an alert.

An alert is a notification row in PostgreSQL, so it cannot be delivered while PostgreSQL is down. The server logs the alerts it owes and delivers them at the first check that can deliver them, including the alert for the PostgreSQL outage itself. An alert for an outage that ended before it could be delivered names the outage window: when the component turned unhealthy and when it recovered. A delivery that fails for another reason is logged and retried at the next check. With `notifications.admin_alerts` off, an outage is logged and no alert is sent, also when the setting is turned off during the outage.

The background worker writes a heartbeat to Redis, and the web server judges it by age. An idle worker renews it about every 10 seconds and reads unhealthy after 45 seconds without a renewal. A worker that is handling a message renews it every 30 seconds and reads unhealthy after 90 seconds. A handler blocked in a single call for longer than that cannot renew it, so the worker reads unhealthy until the call returns. A heartbeat that disappears also reads unhealthy. A heartbeat that says the worker stopped reads not available for 45 seconds and unhealthy after that, so a worker that restarts itself at its memory limit has time to report in again before it would alert. Until the server has seen a heartbeat, the worker check reads not available, so a development stack without a worker does not alert. While Redis is unreachable the worker check reads not available too, and the Redis check carries the alert.

Baander assumes one web server and one background worker per deployment. Two web servers would each send every alert, and two workers share one heartbeat, so a live worker hides a dead one. The web server cannot report its own outage; watch it from outside with the [health endpoints](monitoring.md#health-checks).

## Scope

This page covers admin-configurable notification channels: push key setup, webhook management, and mailer configuration. User-facing notification preference toggling (per-category, per-channel opt-in/out) is not documented here because access control on those endpoints has not been verified for the current release.
