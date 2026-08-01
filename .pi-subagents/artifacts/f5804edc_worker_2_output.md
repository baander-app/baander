# Party @ManyToOne + Indexes Refactor — Complete

## Summary
Refactored 3 Party entities to add @ManyToOne relations and index declarations, resolving all 8 Party-related schema drift statements (2 DROP CONSTRAINT + 6 DROP INDEX).

## Changes

### PartyMemberEntity (`src/Party/Infrastructure/Doctrine/Entity/PartyMemberEntity.php`)
- Replaced `Uuid $userId` → `UserEntity $user` (ManyToOne, `user_id`, CASCADE)
- Replaced `Uuid $sessionId` → `SyncedPartySessionEntity $session` (ManyToOne, `session_id`, CASCADE)
- Added `#[ORM\Index]` for `idx_party_members_user_id` and `idx_party_members_session_id`
- Kept `getUserId()` and `getSessionId()` accessors delegating to relation

### SyncedPartySessionEntity (`src/Party/Infrastructure/Doctrine/Entity/SyncedPartySessionEntity.php`)
- Replaced `Uuid $hostUserId` → `UserEntity $hostUser` (ManyToOne, `host_user_id`, CASCADE)
- Added `#[ORM\Index]` for `idx_party_sessions_is_active` and `idx_party_sessions_video_id`
- Renamed `setHostUserId(Uuid)` → `setHostUser(UserEntity)`
- `videoId` and `transcodeJobId` remain loose UUID columns (no FK constraints in DB)

### PartyEventEntity (`src/Party/Infrastructure/Doctrine/Entity/PartyEventEntity.php`)
- Added `#[ORM\Index]` for `idx_party_events_session_id` and `idx_party_events_occurred_at`
- No ManyToOne changes (no FK constraint drift for this table)

### Repository Updates
- **PartyMemberRepository**: Updated `findOneBy`/`findBy`/`count` to use `user`/`session` keys; constructor uses `$em->getReference()` for both relations
- **SyncedPartySessionRepository**: Updated constructor + `syncToEntity` to use `$em->getReference(UserEntity::class, ...)` and `setHostUser()`
- **PartyEventRepository**: No changes needed (uses loose UUID column queries)

## Validation
- `doctrine:schema:validate` Mapping: **[OK]**
- All 8 Party DROP statements resolved
- Remaining: 1 new ADD CONSTRAINT (`FK_57514DD4613FECDF` for `party_members.session_id` → `party_sessions`) — expected, no FK existed before, migration will create it
- `php -l` passes on all 5 modified files

## Residual Risks
- The `party_members.session_id` FK didn't exist in the DB before; the migration will add it. If there are orphaned party_members rows referencing non-existent sessions, the FK creation will fail. Low risk in dev.
- FK constraint names will change from custom (`fk_party_members_user_id`) to Doctrine hashed names (`FK_<hash>`). Functionally equivalent.