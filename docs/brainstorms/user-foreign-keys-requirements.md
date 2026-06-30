# Requirements: Add Missing Foreign Keys on user_id Columns

**Date:** 2026-05-23
**Status:** Draft
**Bounded Contexts:** Cross-cutting (affects UserPreference, Session, Radio, Notification, Party, Transcode)

## Problem

Many tables with `user_id` columns are missing foreign key constraints to the `users` table. This allows:
- Orphaned records to exist (user_id pointing to deleted users)
- No automatic cleanup when users are deleted
- Potential data integrity issues

## Solution

Add foreign key constraints with `ON DELETE CASCADE` to all tables missing them.

## Tables Affected

| Table | Context | Current State |
|-------|---------|---------------|
| `audio_preferences` | UserPreference | No FK |
| `player_preferences` | UserPreference | No FK |
| `layout_preferences` | UserPreference | No FK |
| `preference_history` | UserPreference | No FK |
| `user_sidebar_configs` | UserPreference | No FK |
| `user_accent_colors` | UserPreference | No FK |
| `listening_sessions` | Session | No FK |
| `devices` | Session | No FK |
| `radio_sessions` | Radio | No FK |
| `starred_stations` | Radio | No FK |
| `country_subscriptions` | Radio | No FK |
| `notifications` | Notification | No FK |
| `notification_preferences` | Notification | No FK |
| `party_sessions` (host_user_id) | Party | No FK |
| `party_members` (user_id) | Party | No FK |
| `transcode_sessions` | Transcode | No FK |

**Note:** These tables already have FKs and are NOT affected:
- `passkeys`, `third_party_credentials`, `oauth_access_tokens`, `oauth_auth_codes`, `oauth_device_codes`
- `user_libraries`, `playlists`, `playlist_collaborators`, `media_activities`
- `recommendations`, `eq_device_profiles`

## Implementation Approach

1. **Single migration file:** `VersionXXX_AddMissingUserForeignKeys.php`

2. **Cleanup step:** Before adding FKs, delete any orphaned records:
   ```sql
   DELETE FROM {table} WHERE user_id NOT IN (SELECT id FROM users);
   ```

3. **Add foreign keys:**
   ```sql
   ALTER TABLE {table} ADD CONSTRAINT fk_{table}_user_id
   FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
   ```

4. **For `party_sessions.host_user_id`:**
   ```sql
   ALTER TABLE party_sessions ADD CONSTRAINT fk_party_sessions_host_user_id
   FOREIGN KEY (host_user_id) REFERENCES users(id) ON DELETE CASCADE;
   ```

## Success Criteria

- All 15 tables have foreign key constraints to `users(id)`
- All constraints use `ON DELETE CASCADE`
- Migration runs successfully without orphaned data errors
- User deletion cascades to all related data

## Non-Goals

- Modifying existing foreign keys
- Changing entity annotations (most already have proper JoinColumn)
- Adding indexes on user_id (already exist where needed)
