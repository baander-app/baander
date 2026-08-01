# Notification @ManyToOne Refactor — Complete

## Summary
Refactored 4 Notification entities to replace loose `Uuid $xxxId` columns with proper `@ManyToOne` entity relations, eliminating FK constraint drift for the Notification bounded context.

## Entities Changed

### 1. NotificationEntity (`notifications`)
- Replaced `Uuid $userId` column → `UserEntity $user` ManyToOne (JoinColumn: `user_id`, CASCADE)
- Added `getUser()` and `setUser(UserEntity)`; kept `getUserId(): Uuid` returning `$this->user->getId()`
- Existing composite indexes (`idx_notifications_user_created`, `idx_notifications_user_read`) preserved

### 2. NotificationPreferenceEntity (`notification_preferences`)
- Replaced `Uuid $userId` column → `UserEntity $user` ManyToOne (CASCADE)
- Added `getUser()` / `setUser()`; kept `getUserId()` accessor

### 3. PushSubscriptionEntity (`push_subscriptions`)
- Replaced `Uuid $userId` constructor param → `UserEntity $user` (CASCADE)
- Changed constructor signature; kept `getUserId()` accessor

### 4. WebhookDeliveryLogEntity (`webhook_delivery_logs`)
- Replaced `Uuid $webhookId` column → `WebhookEntity $webhook` ManyToOne (CASCADE)
- Added `getWebhook()` / `setWebhook()`; kept `getWebhookId()` accessor

## Caller Updates

- **NotificationRepository**: `setUserId()` → `setUser(getReference(...))`; all DQL `e.userId` → `e.user` (4 queries)
- **NotificationPreferenceRepository**: `setUserId()` → `setUser(getReference(...))`; `findOneBy(['userId'=>...])` → `['user'=>...]` (3 queries)
- **PushSubscriptionRepository**: `findBy(['userId'=>...])` → `['user'=>...]`
- **PushSubscriptionController**: Injected `EntityManagerInterface`; constructor uses `getReference()` for user
- **WebhookDeliveryService**: `setWebhookId($webhook->getId())` → `setWebhook($webhook)` (entity already in scope)

## Verification
- `cache:clear`: OK
- `doctrine:schema:validate` Mapping: **[OK]** (0 FAIL)
- Notification-related FK/index drift: **resolved** (only `notifications.public_id` TYPE mismatch remains — pre-existing Category 1 column drift, migration territory)
- `lint:container`: no Notification errors (only pre-existing Transcode `HardwareCapabilitiesProber` env type error)