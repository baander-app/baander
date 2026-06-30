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

All webhook endpoints live under `/api/webhooks`.

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/webhooks` | List all configured webhooks |
| `POST` | `/api/webhooks` | Create a new webhook |
| `PUT` | `/api/webhooks/{id}` | Update a webhook |
| `DELETE` | `/api/webhooks/{id}` | Delete a webhook |

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
  "id": "0197d2ef-...",
  "url": "https://hooks.slack.com/services/...",
  "category_filter": ["security", "media_changes"],
  "secret": "a1b2c3d4...",
  "created_at": "2026-04-21T10:00:00+00:00",
  "updated_at": "2026-04-21T10:00:00+00:00"
}
```

### Updating and deleting

Use `PUT /api/webhooks/{id}` to change the `url` or `category_filter`. Omitting a field leaves it unchanged. Use `DELETE /api/webhooks/{id}` to remove a webhook entirely.

### HMAC signature verification

Each webhook delivery includes two headers for verifying authenticity:

| Header | Description |
|--------|-------------|
| `X-Webhook-Timestamp` | Unix timestamp of the delivery |
| `X-Webhook-Signature` | HMAC-SHA256 signature over `{timestamp}.{payload}` |

To verify a delivery on your receiving end, compute the HMAC of the raw timestamp and body using the secret you received at creation time, then compare it with the header value.

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

The Docker development environment includes [Mailpit](https://mailpit.axllent.org/) as a local SMTP server for testing. The default development DSN is `smtp://mailpit:1025`. See the Mail section in [configuration.md](configuration.md#mail) for full details.

## Scope

This page covers admin-configurable notification channels: push key setup, webhook management, and mailer configuration. User-facing notification preference toggling (per-category, per-channel opt-in/out) is not documented here because access control on those endpoints has not been verified for the current release.
