# Notifications

When a notification appears in the app but no email or push arrives, creation has
succeeded and a later delivery stage needs investigation. Baander stores the in-app
notification in PostgreSQL and dispatches separate email, push and webhook messages
to [background work](background-work.md). A stored notification or accepted message
is not proof that an external receiver received it.

Run commands on the installation host, from its checkout, with the
[`dc` wrapper](getting-started.md). Keep the worker running after configuration;
restarting the web service alone does not refresh a separately managed worker.

## Push Notifications (VAPID)

Browser push notifications use the [Web Push protocol](https://developer.mozilla.org/en-US/docs/Web/API/Push_API) with VAPID (Voluntary Application Server Identification) for server authentication. Users subscribe from their browser after you configure the keys.

### Setup

1. **Generate a VAPID key pair:**

```bash
dc exec app php bin/console app:generate-vapid-keys
```

See the [app:generate-vapid-keys](commands/app-generate-vapid-keys.md) command reference for full details.

2. **Add the keys to the protected web configuration and worker credentials:**

```env
VAPID_PUBLIC_KEY=BIj3...
VAPID_PRIVATE_KEY=MIGT...
```

Both variables are documented in [configuration.md](configuration.md#web-push-vapid).

3. **Apply the new environment to the web service and background worker.** Follow
   the [configuration lifecycle](configuration.md#where-a-change-takes-effect) and
   [Background work](background-work.md). Recreate containers when changing values
   injected by Compose; restarting an existing container retains its environment.

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

The response includes a `secret` value on creation. Store it securely: it is shown
once for that value. Rotation generates and returns a new value once.

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

### Delivery behavior

- **Attempts:** Each delivery runs at most three HTTP attempts, waiting 1s and 2s
  between them. Network failures, 5xx, 408 and 429 can retry. Other 3xx/4xx responses
  are permanent failures; redirects are not followed.
- **Worker retries:** A failed delivery escapes to the worker's retry policy. A
  later message retry can resend to a receiver that already accepted an earlier
  attempt, including another destination in the same message. Receivers must
  deduplicate `Idempotency-Key`; see [Background work](background-work.md).
- **Timeout:** Each HTTP attempt has a 10-second timeout and maximum duration.
- **Destination checks:** Every DNS answer must be public or fall in an explicitly
  permitted LAN range from `WEBHOOK_LAN_ALLOWLIST`. Loopback, link-local and metadata
  endpoints remain blocked. The selected address is pinned for sending. Resolution
  or validation failure is logged as a failed delivery and can retry.
- **Observation:** Webhook delivery logs record success for a 2xx response, or
  failure with an HTTP status when available. A 2xx proves receiver acceptance,
  not that its downstream action completed. Worker logs use the
  `notification.webhook` channel.
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

Set `MAILER_DSN` in the protected configuration used by the delivery worker:

```env
MAILER_DSN=smtp://user:pass@smtp.baander.app:587
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

The web server creates administrator notifications when a component becomes
unhealthy. PostgreSQL must be writable to store them, and the worker must be able
to process the outgoing delivery messages. Alert state, timing, delayed delivery
and the worker heartbeat belong to [Monitoring](monitoring.md#what-is-checked).
Watch the web server from outside too: it cannot report its own outage.

## Checking a missing delivery

1. Check whether the in-app notification exists. If it does, investigate delivery
   rather than recreating the event and risking duplicate messages.
2. Check the [worker and failed work](background-work.md) and
   [component health](monitoring.md). Queue acceptance alone does not establish
   completion.
3. For push, check `notifications.push_enabled`, the user's channel/category
   preference, browser permission and a current subscription. The worker removes
   expired subscriptions; other send failures are logged and raised for retry.
   Push has no stored per-delivery outcome. Provider acceptance does not prove a
   browser displayed the notification.
4. For email, check that the user was verified when the email message was created,
   then their channel/category preference and the configured mail transport.
   `notification.email` errors identify failed sends; a successful transport handoff
   does not prove inbox delivery. Check the receiving mail service separately.
5. For webhooks, check the category filter, destination validation, signing-secret
   decryption and receiver status. Search `notification.webhook` logs using the
   notification ID, and confirm the receiver's deduplication and signature checks.

Keep `APP_SECRET` with database backups: webhook secrets need the matching value.
[Storage and data](storage-and-data.md) explains ownership, and
[Backup and recovery](backup-and-recovery.md) covers capture and restore limits.

## Scope

This page covers admin-configurable notification channels: push key setup, webhook management, and mailer configuration. User-facing notification preference toggling (per-category, per-channel opt-in/out) is not documented here because access control on those endpoints has not been verified for the current release.
