# Session @ManyToOne Refactor — Complete

## Summary
Refactored 2 Session entities to add `@ManyToOne UserEntity` relations on `user_id`, replacing loose `Uuid $userId` columns. Both FKs use `nullable: false, onDelete: CASCADE`.

## Changes

### DeviceEntity (`devices`, FK `fk_devices_user_id`)
- Replaced `Uuid $userId` column with `UserEntity $user` ManyToOne + JoinColumn
- Constructor: `Uuid $userId` → `UserEntity $user`
- `getUserId()` returns `$this->user->getId()`
- Added missing `#[ORM\UniqueConstraint(name: 'devices_user_id_device_id_key', columns: ['user_id', 'device_id'])]` that the entity didn't declare but the DB had
- `$deviceId` field left unchanged (separate from PK `$id`)

### ListeningSessionEntity (`listening_sessions`, FK `fk_listening_sessions_user_id`)
- Replaced `Uuid $userId` column with `UserEntity $user` ManyToOne + JoinColumn
- Constructor: `Uuid $userId` → `UserEntity $user`
- `getUserId()` returns `$this->user->getId()`
- `active_device_id` left as nullable `Uuid` — no FK constraint in DB, no DeviceEntity relation needed

### Repositories updated
- `DeviceDoctrineRepository`: `findOneBy(['userId' => $uuid])` → `['user' => $uuid]`; construction uses `$em->getReference(UserEntity::class, $uuid)`
- `ListeningSessionDoctrineRepository`: same pattern

## Verification
- `cache:clear` — OK
- `doctrine:schema:validate` — Mapping [OK]
- `doctrine:schema:update --dump-sql | grep devices|listening_sessions` — **NO DRIFT** (clean)
- No external callers beyond the repositories
