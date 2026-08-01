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
| `SendEmailCommand` | `SendEmailHandler` | Deliver an email notification |
| `SendPushCommand` | `SendPushHandler` | Deliver a Web Push notification |
| `SendWebhookCommand` | `SendWebhookHandler` | Deliver a notification to an external webhook |

## Ports

None. The Notification context does not define application ports.

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

> **Known design gap:** `markRead` and `delete` do not check `userId` — any authenticated user can read or delete another user's notification by guessing its `publicId`.

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
| `NotificationVoter` | Authorization — ensures users can only access their own notifications. Note: `markRead` and `delete` endpoints do not currently route through the voter (see known design gap above). |

### PublicId Type Gotcha

The `NotificationRepository.findByPublicId()` method must pass a `PublicId` value object to the custom `PublicIdType` Doctrine column, not a raw string. Passing a string causes the type converter to throw, resulting in a 500 error. Always wrap: `PublicId::fromString($id)`.
