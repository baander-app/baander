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
| `ListWebhooksQuery` | `ListWebhooksHandler` | List the configured webhooks, oldest first, without secrets (`GET /api/webhooks` and `app:webhook:list`) |
| `CreateWebhookCommand` | `CreateWebhookHandler` | Add a webhook and issue its signing secret (`POST /api/webhooks` and `app:webhook:create`) |
| `UpdateWebhookCommand` | `UpdateWebhookHandler` | Change a webhook's URL, its category filter, or both (`PUT /api/webhooks/{id}` and `app:webhook:update`) |
| `RotateWebhookSecretCommand` | `RotateWebhookSecretHandler` | Replace a webhook's signing secret; the webhook keeps its signing version (`POST /api/webhooks/{id}/rotate-secret` and `app:webhook:rotate-secret`) |
| `DeleteWebhookCommand` | `DeleteWebhookHandler` | Delete a webhook (`DELETE /api/webhooks/{id}` and `app:webhook:delete`) |

## Webhook Administration

`WebhookController` and the `app:webhook:*` commands dispatch the same commands and query, so the API and the shell share one use case for each action. The commands carry the input as the client sent it; the handlers validate it with `WebhookInput`. An ID that is not a UUID, a missing URL, a destination that `WebhookDestinationPortInterface` does not resolve to an allowed address, or a category filter that is neither null nor a list of supported categories raises `InvalidInputException` (HTTP 422, console exit `INVALID`). An unknown webhook raises `NotFoundException` (HTTP 404). `UpdateWebhookCommand` changes a field only when its `changes*` flag is set. A null category filter with the flag set clears the filter; without the flag, the filter stays as it is.

Create and rotate each generate a secret of 64 hex characters from `random_bytes(32)`, store it encrypted through `WebhookSecretPortInterface`, and return the plain secret once in an `IssuedWebhookSecret`. `WebhookRepositoryInterface` takes the encrypted secret and returns `WebhookView`, which never carries it, so list and update cannot return a secret.

## Ports

| Port | Purpose | Implemented By |
|------|---------|----------------|
| `WebhookRepositoryInterface` | Store webhook configuration; takes the encrypted secret and returns `WebhookView` without it | `WebhookRepository` |
| `WebhookSecretPortInterface` | Encrypt and decrypt webhook signing secrets | `WebhookSecretCodec` |
| `WebhookDestinationPortInterface` | Resolve a webhook URL to its host and allowed IP addresses, or null | `WebhookDestinationPolicy` |
| `PushSubscriptionRegistrationPortInterface` | Register a push endpoint for a user or rotate its owner's credentials | `PushSubscriptionRepository` |
| `PushSubscriptionRemovalPortInterface` | Remove one or all of a user's push subscriptions | `PushSubscriptionRepository` |

The context reads the recipient's email language through UserPreference's `UserSettingsContractInterface`.

## Email Language

`SendEmailCommand` carries translation keys, not text: the title and body keys and their parameters. A parameter is a scalar or a `TranslatableParameter`, so a fallback value such as an unknown user name is translated too and no English fragment lands in a Danish or Thai email. `SendEmailHandler` asks `UserSettingsContractInterface::resolveLanguage()` for the recipient's language when the worker sends the email, then translates the subject, header, title, body and template text in that locale, passing it explicitly; it never changes the shared translator's locale. A language changed after the notification was queued, such as a new server default, therefore applies to the email, and the email can still be written when the user has deleted the notification in the meantime. If the lookup or the send fails, the handler logs the failure and rethrows, so Messenger retries the message.

`NotificationMessagePayloadCodec` writes this shape and rejects messages queued in the earlier shape, which carried English text.

In-app notifications, push and webhook text stay in English. `CreateNotificationHandler` translates them with `SupportedLanguages::FALLBACK` explicitly rather than in whatever locale the shared translator has.

## Server Settings

`NotificationSettingDefinitions` contributes `notifications.push_enabled` (default `true`). While it is off, `SendPushHandler` skips the push and logs the skip; the notification itself is still created and the other channels are unaffected.

`notifications.admin_alerts` (default `true`) is defined in Shared's `SharedSettingDefinitions`, because the health monitor that reads it lives in Shared. While it is off, `HealthAlertService` logs a health degradation without alerting administrators. Other admin alerts, such as new user registrations, are always sent.

The web server runs the health monitor. `HealthMonitorSubscriber` starts a Swoole timer in HTTP worker 0 that calls `HealthAlertService::checkAndAlert()` every `HEALTH_MONITOR_INTERVAL_SECONDS` (default 60). Each component's alert state (healthy, pending or acknowledged, with the outage window) lives in `HealthAlertTable`, a `Swoole\Table` created before the server forks its workers, so worker reloads keep it; `HealthAlertTransition` holds the rules as pure functions. A component that reads unhealthy owes one alert until it is delivered, also after the component recovers. Delivery reads the setting and saves the notifications through `AdminAlertPortInterface` inside one error boundary per component: a failure is logged, and the next tick retries. No delivery is tried while PostgreSQL reads unhealthy. Migration `Version20261010110000` removes the five-minute **Check system health** job that earlier ran the checks in the worker. See [Health alerts](../../part-1-operator-guide/notifications.md#health-alerts) for what operators see.

`SendPushHandler` reads its setting for every message and `HealthAlertService` reads its setting for every alert it delivers, so a change applies without a restart. See [Settings](shared.md#settings) for the mechanism.

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

The entire controller is gated with `#[IsGranted('ROLE_ADMIN')]` — only admins can manage webhooks.

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| GET | `/api/webhooks` | `WebhookController` | List all webhooks (admin) |
| POST | `/api/webhooks` | `WebhookController` | Create a webhook (admin, returns one-time secret) |
| PUT | `/api/webhooks/{id}` | `WebhookController` | Update a webhook (admin) |
| POST | `/api/webhooks/{id}/rotate-secret` | `WebhookController` | Rotate a webhook's secret (admin, returns one-time secret) |
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

`WebhookRepository` implements `WebhookRepositoryInterface` over `WebhookEntity`.

### Webhook Delivery

| Component | Purpose |
|-----------|---------|
| `DiscordAdapter` | Formats and delivers payloads to Discord |
| `SlackAdapter` | Formats and delivers payloads to Slack |
| `HmacSigner` | Signs webhook payloads with HMAC for verification |
| `WebhookDeliveryService` | Orchestrates webhook delivery and retry logic |

### Console Commands

| Command | Purpose |
|---------|---------|
| `app:webhook:list` (`WebhookListCommand`) | List the configured webhooks, without their secrets |
| `app:webhook:create` (`WebhookCreateCommand`) | Add a webhook and print its signing secret once |
| `app:webhook:update` (`WebhookUpdateCommand`) | Change a webhook's URL or categories; `--category` replaces the filter and `--all-categories` clears it |
| `app:webhook:rotate-secret` (`WebhookRotateSecretCommand`) | Replace a webhook's signing secret and print the new one once |
| `app:webhook:delete` (`WebhookDeleteCommand`) | Delete a webhook |
| `app:generate-vapid-keys` (`GenerateVapidKeysCommand`) | Generate VAPID keys for Web Push |

### Security

| Component | Purpose |
|-----------|---------|
| `NotificationVoter` | Authorization voter for notification ownership. No controller calls it; `markRead` and `delete` check ownership in `NotificationController`. |

### PublicId Type Gotcha

The `NotificationRepository.findByPublicId()` method must pass a `PublicId` value object to the custom `PublicIdType` Doctrine column, not a raw string. Passing a string causes the type converter to throw, resulting in a 500 error. Always wrap: `PublicId::fromString($id)`.
