# Notification

The Notification context handles in-app notifications, push notifications (Web Push via VAPID), email notifications, and webhook delivery to external services (Discord, Slack). It supports per-user notification preferences and HMAC-signed webhook delivery for security.

## Domain Models

| Model | Type | Purpose |
|-------|------|---------|
| `Notification` | Aggregate Root | In-app notification with read/unread state |
| `NotificationPreference` | Model | Per-user notification preferences |
| `NotificationCategory` | Value Object (enum) | Category of a notification event |
| `NotificationChannel` | Value Object (enum) | Delivery channel (InApp, Email, Push, Webhook) |

## Domain Events

| Event | Purpose |
|-------|---------|
| `NotificationEvent` | Base event for all notification types |

## Domain Services

| Service | Purpose |
|---------|---------|
| `EventCategoryResolver` | Maps domain events to notification categories |
| `NotificationContentResolver` | Builds notification content from domain events |

## Commands and Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `CreateNotificationCommand` | `CreateNotificationHandler` | Create an in-app notification |
| `SeedDefaultPreferencesCommand` | `SeedDefaultPreferencesHandler` | Initialize default preferences for a user |
| `SendEmailCommand` | `SendEmailHandler` | Deliver an email notification in the recipient's email language |
| `SendPushCommand` | `SendPushHandler` | Deliver a Web Push notification while `notifications.push_enabled` is on |
| `SendWebhookCommand` | `SendWebhookHandler` | Deliver a notification to an external webhook |

## Ports

None. The Notification context does not define application ports. It reads the recipient's email language through UserPreference's `UserSettingsContractInterface`.

## Email Language

`SendEmailCommand` carries translation keys, not text: the title and body keys and their parameters. A parameter is a scalar or a `TranslatableParameter`, so a fallback value such as an unknown user name is translated too and no English fragment lands in a Danish or Thai email. `SendEmailHandler` asks `UserSettingsContractInterface::resolveLanguage()` for the recipient's language when the worker sends the email, then translates the subject, header, title, body and template text in that locale, passing it explicitly; it never changes the shared translator's locale. A language changed after the notification was queued, such as a new server default, therefore applies to the email, and the email can still be written when the user has deleted the notification in the meantime. If the lookup or the send fails, the handler logs the failure and rethrows, so Messenger retries the message.

`NotificationMessagePayloadCodec` writes this shape and rejects messages queued in the earlier shape, which carried English text.

In-app notifications, push and webhook text stay in English. `CreateNotificationHandler` translates them with `SupportedLanguages::FALLBACK` explicitly rather than in whatever locale the shared translator has.

## Server Settings

`NotificationSettingDefinitions` contributes `notifications.push_enabled` (default `true`). While it is off, `SendPushHandler` skips the push and logs the skip; the notification itself is still created and the other channels are unaffected.

`notifications.admin_alerts` (default `true`) is defined in Shared's `SharedSettingDefinitions`, because the health monitor that reads it lives in Shared. While it is off, `HealthAlertService` logs a health degradation without alerting administrators. Other admin alerts, such as new user registrations, are always sent. Migration `Version20261007140000` seeds the **Check system health** job, which dispatches Shared's `CheckHealthCommand` every five minutes; `CheckHealthHandler` calls `HealthAlertService` through `HealthAlertPortInterface`. The service remembers each component's previous status in memory, so it alerts on a change from healthy, not on every run.

Both handlers read the setting for every message, so a change applies without a restart. See [Settings](shared.md#settings) for the mechanism.

## API Endpoints

All endpoints are prefixed with `/api`.

### Notifications

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| GET | `/api/notifications` | `NotificationController` | List user's notifications (paginated) |
| GET | `/api/notifications/unread-count` | `NotificationController` | Get unread notification count |
| PATCH | `/api/notifications/{publicId}/read` | `NotificationController` | Mark notification as read |
| PATCH | `/api/notifications/read-all` | `NotificationController` | Mark all notifications as read |
| DELETE | `/api/notifications/{publicId}` | `NotificationController` | Delete a notification |

`markRead` and `delete` load the notification by `publicId` and refuse it unless it belongs to the signed-in user.

### Preferences

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| GET | `/api/notifications/preferences` | `PreferenceController` | List notification preferences |
| PUT | `/api/notifications/preferences` | `PreferenceController` | Update notification preferences |

### Push Subscriptions

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| POST | `/api/push/subscribe` | `PushSubscriptionController` | Subscribe to Web Push |
| DELETE | `/api/push/subscribe` | `PushSubscriptionController` | Unsubscribe from Web Push |
| DELETE | `/api/push/subscriptions` | `PushSubscriptionController` | Remove all push subscriptions |

### Webhooks

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
The entire controller is gated with `#[IsGranted('ROLE_ADMIN')]` — only admins can manage webhooks.

| GET | `/api/webhooks` | `WebhookController` | List all webhooks (admin) |
| POST | `/api/webhooks` | `WebhookController` | Create a webhook (admin, returns one-time secret) |
| PUT | `/api/webhooks/{id}` | `WebhookController` | Update a webhook (admin) |
| DELETE | `/api/webhooks/{id}` | `WebhookController` | Delete a webhook (admin) |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|-------------|
| Depends on | Shared | Uses `Uuid`, `PublicId`, `CursorPaginatedResponse` |
| Depends on | Auth | User identification and authentication |
| Depends on | UserPreference | `SendEmailHandler` resolves the email language through `UserSettingsContractInterface` (the `UserPreference Settings Contract` Deptrac layer) |
| Depends on | Shared | `SystemSettingsPortInterface` for `notifications.push_enabled` |
| Depended on by | All contexts | Any context that emits domain events can trigger notifications |

## Infrastructure

### Doctrine Entities

| Entity | Purpose |
|--------|---------|
| `NotificationEntity` | In-app notification persistence |
| `NotificationPreferenceEntity` | User notification preferences |
| `PushSubscriptionEntity` | Web Push subscription data |
| `WebhookEntity` | Webhook configuration |
| `WebhookDeliveryLogEntity` | Webhook delivery audit log |

### Webhook Delivery

| Component | Purpose |
|-----------|---------|
| `DiscordAdapter` | Formats and delivers payloads to Discord |
| `SlackAdapter` | Formats and delivers payloads to Slack |
| `HmacSigner` | Signs webhook payloads with HMAC for verification |
| `WebhookDeliveryService` | Orchestrates webhook delivery and retry logic |

### Security

| Component | Purpose |
|-----------|---------|
| `NotificationVoter` | Authorization voter for notification ownership. No controller calls it; `markRead` and `delete` check ownership in `NotificationController`. |

### PublicId Type Gotcha

The `NotificationRepository.findByPublicId()` method must pass a `PublicId` value object to the custom `PublicIdType` Doctrine column, not a raw string. Passing a string causes the type converter to throw, resulting in a 500 error. Always wrap: `PublicId::fromString($id)`.
