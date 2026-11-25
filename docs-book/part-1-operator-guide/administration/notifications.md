# Notifications

When a notification appears in the app but no email or push arrives, creation
succeeded and the fault lies in a later delivery stage. Baander stores the in-app
notification in PostgreSQL, then dispatches separate email, push and webhook messages
to [background work](../workers/background-work.md). A stored notification or an accepted message
does not prove that an external receiver got it.

Run commands on the installation host, from its checkout, with the
[`dc` function](../getting-started/installation.md#command-context). The worker sends every
notification email, push and webhook, so keep it running once delivery is configured.
Restarting the web service alone does not refresh the worker pair.

## Push Notifications (VAPID)

Browser push notifications use the [Web Push protocol](https://developer.mozilla.org/en-US/docs/Web/API/Push_API) with VAPID (Voluntary Application Server Identification) for server authentication. A client subscribes through `POST /api/push/subscribe` after you configure the keys. The web application has no screen that subscribes, so a browser that only runs the web application receives no push.

### Setup

1. **Generate a VAPID key pair:**

```bash
dc exec web php bin/console app:generate-vapid-keys
```

See the [app:generate-vapid-keys](../commands/app-generate-vapid-keys.md) command reference for full details.

2. **Add the keys to the `environment` object of the installation's private configuration:**

```json
"environment": {
    "VAPID_PUBLIC_KEY": "BIj3...",
    "VAPID_PRIVATE_KEY": "MIGT..."
}
```

[Environment variables](../configuration/environment-variables.md#web-push-vapid) documents both.

3. **Apply the new environment to the web service and the worker pair.** From the
   checkout, run the preset's `start`, which retires the worker pair, stops the
   services and starts them with the new environment; the preset passes both keys
   to web and to the workers. See the
   [configuration lifecycle](../configuration/README.md#where-a-change-takes-effect) and
   [Background work](../workers/background-work.md). Restarting an existing container
   retains its environment.

   ```bash
   php scripts/deploy-preset.php start --config /srv/baander/preset.json --state /srv/baander/preset-state
   ```

A client that implements the subscription can then register for push notifications; the web application does not. To rotate the keys later, follow [VAPID key rotation](../maintenance/credential-rotation.md#rotating-vapid-keys) in the security guide.

## Webhooks

An outgoing webhook delivers notifications to an external service, such as Slack, Discord or a custom integration, as an HTTP POST. Only administrators manage webhooks: the API requires the `ROLE_ADMIN` role.

### Webhook API

All webhook endpoints live under `/api/webhooks`. Each one has a console command for operators with shell access: [app:webhook:list](../commands/app-webhook-list.md), [app:webhook:create](../commands/app-webhook-create.md), [app:webhook:update](../commands/app-webhook-update.md), [app:webhook:delete](../commands/app-webhook-delete.md) and [app:webhook:rotate-secret](../commands/app-webhook-rotate-secret.md).

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

The creation response includes the signing `secret`. Store it securely: Baander
returns each secret value only once, at creation or at the rotation that generated it.

```json
{
  "data": {
    "publicId": "kTqWbNzRhLmPxVcDfGsJa",
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

The `{id}` in these paths is the webhook's `publicId`. Use `PUT /api/webhooks/{id}` to change the `url` or `category_filter`; a field you omit stays unchanged. Use `DELETE /api/webhooks/{id}` to remove a webhook entirely.

### HMAC signature verification

Each webhook delivery uses signature protocol version 2 and includes these headers:

| Header | Description |
|--------|-------------|
| `X-Baander-Webhook-Signature-Version` | Always `2` |
| `X-Baander-Webhook-Timestamp` | Unix timestamp of the delivery |
| `X-Baander-Webhook-Signature` | HMAC-SHA256 signature over `{timestamp}.{payload}` |

To verify a delivery, compute `sha256=` followed by the hexadecimal HMAC-SHA256
of the header timestamp, a literal `.`, and the exact raw request body, keyed with
the plain secret returned at creation or rotation. Use the body exactly as received:
re-encoding the JSON changes the signed bytes. Compare the complete signature with a
constant-time comparison, reject timestamps outside your receiver's freshness window,
and deduplicate the `Idempotency-Key` header.

Baander stores the webhook secret encrypted with its application secret, so keep
that application secret stable and protected. Rotation takes effect once the
database flush succeeds, and later deliveries are signed with the new secret: give
it to the receiver before further notifications go out.

### Delivery behavior

- **Attempts:** Each delivery runs at most three HTTP attempts, waiting 1s and 2s
  between them. Network failures, 5xx, 408 and 429 can retry. Other 3xx/4xx responses
  are permanent failures; redirects are not followed.
- **Worker retries:** A failed delivery escapes to the worker's retry policy. A
  later message retry can resend to a receiver that already accepted an earlier
  attempt, including another destination in the same message. Receivers must
  deduplicate `Idempotency-Key`; see [Background work](../workers/background-work.md).
- **Timeout:** Each HTTP attempt has a 10-second timeout and maximum duration.
- **Destination checks:** Every DNS answer must be public or fall in an explicitly
  permitted LAN range from `WEBHOOK_LAN_ALLOWLIST`. Loopback, link-local, site-local
  and metadata endpoints remain blocked. An IPv6 address that carries an IPv4
  address, such as a NAT64 (`64:ff9b::/96`) or 6to4 address, is checked, and matched
  against the allowlist, as that IPv4 address. The selected address is pinned for
  sending. Resolution or validation failure is logged as a failed delivery and can
  retry.
- **Observation:** Webhook delivery logs record success for a 2xx response, or
  failure with an HTTP status when available. A 2xx proves receiver acceptance,
  not that its downstream action completed. Worker logs use the
  `notification.webhook` channel.
- **User-Agent:** Requests are sent with `Baander-Webhook/1.0`.

### Notification categories

A category filter selects which notifications trigger a webhook. The available categories are:

| Category | Description |
|----------|-------------|
| `security` | Authentication events, password changes, security alerts |
| `background_jobs` | Library scan results. Transcode completion and failure also map here, but nothing raises those events yet |
| `media_changes` | New albums. Nothing raises the album-created event yet, so no notification arrives in this category |

Set `category_filter` to `null` (or omit it when creating) to receive all categories.

## Email

Baander sends notification email through Symfony's Mailer component, using the transport configured in the environment.

### Configuration

Set `MAILER_DSN` in the protected configuration used by the delivery worker:

```env
MAILER_DSN=smtp://user:pass@smtp.baander.app:587
```

The Docker development environment includes [Mailpit](https://mailpit.axllent.org/) as a local SMTP server for testing; its default development DSN is `smtp://mailpit:1025`. The [Mail](../configuration/environment-variables.md#mail) section of the environment variable reference covers the remaining mail settings, including the sender address.

Password reset emails use the same transport but are not notifications: they ignore notification preferences and are sent whenever a user asks for a reset. See [Password reset](../configuration/account-security.md#password-reset).

### Language

Notification emails are written in each recipient's [email language](../configuration/server-settings.md#email-language): the user's own choice, otherwise the server default `i18n.default_language`, otherwise English. The worker looks the language up when it sends each email, so a change made after the notification was queued applies to it. In-app notifications, push notifications and webhook payloads are always in English.

## Server Settings

Two [server settings](../configuration/server-settings.md#server-settings) switch parts of notification delivery off for the whole server. Baander reads them for every message, so a change applies without a restart.

| Setting | Default | While it is off |
|---------|---------|-----------------|
| `notifications.push_enabled` | `true` | No browser push is sent. The notification is still created and delivered in the app and by any other channel, and each skipped push is logged. |
| `notifications.admin_alerts` | `true` | A component that turns unhealthy no longer alerts administrators; the degradation is still logged. Other admin alerts, such as new user registrations, are always created. |

### Health alerts

When a component becomes unhealthy, the web server creates an in-app notification
for each administrator. A health alert is only that stored row: Baander sends no
email, push or webhook for it, so PostgreSQL must be writable for the alert to
exist. [Monitoring](../operations/health-and-metrics.md#what-is-checked) covers alert
state, timing, delayed delivery and the worker heartbeat.
Watch the web server from outside too: it cannot report its own outage.

## Checking a missing delivery

1. Check whether the in-app notification exists. If it does, investigate delivery
   rather than recreating the event and risking duplicate messages.
2. Check the [worker and failed work](../workers/background-work.md) and
   [component health](../operations/README.md). Queue acceptance alone does not establish
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

Keep `APP_SECRET` with database backups: the stored webhook secrets decrypt only with the matching value.
[Storage and data](../getting-started/storage-and-data.md) explains ownership, and
[Backup and recovery](../maintenance/backup-and-recovery.md) covers capture and restore limits.

## Scope

This page covers admin-configurable notification channels: push key setup, webhook management, and mailer configuration. User-facing notification preference toggling (per-category, per-channel opt-in/out) is not documented here because access control on those endpoints has not been verified for the current release.
