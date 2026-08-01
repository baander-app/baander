# Radio @ManyToOne Refactor — Findings

## Summary
Converted all 4 Radio entities from loose `Uuid $xxxId` columns to proper `@ManyToOne` relations. Updated 4 repositories to use `$em->getReference()` for entity construction and relation-based query fields.

## Entities changed

### CountrySubscriptionEntity
- `Uuid $userId` → `UserEntity $user` (ManyToOne, JoinColumn `user_id`, CASCADE)
- `Uuid $sourceId` → `RadioSourceEntity $source` (ManyToOne, JoinColumn `source_id`, no onDelete)
- `getUserId()` / `getSourceId()` accessors preserved, delegate to relation's `getId()`
- No new indexes (unique constraint `country_subscriptions_user_id_source_id_country_code_key` covers `user_id`)

### RadioSessionEntity
- `Uuid $userId` → `UserEntity $user` (ManyToOne, JoinColumn `user_id`, CASCADE)
- `?Uuid $activeStationId` → `?RadioStationEntity $activeStation` (ManyToOne, JoinColumn `active_station_id`, nullable: true)
- `getActiveStationId()` preserved, delegates to `$this->activeStation?->getId()`
- `setActiveStationId()` removed → replaced with `setActiveStation(?RadioStationEntity)`. Repository resolves references.
- No new indexes (unique constraint `radio_sessions_user_id_key` covers `user_id`)

### RadioStationEntity
- `Uuid $sourceId` → `RadioSourceEntity $source` (ManyToOne, JoinColumn `source_id`, no onDelete)
- `getSourceId()` preserved
- Added `#[ORM\Index(name: 'idx_radio_stations_country', columns: ['country'])]`
- Added `#[ORM\Index(name: 'idx_radio_stations_source_country', columns: ['source_id', 'country'])]`

### StarredStationEntity
- `Uuid $userId` → `UserEntity $user` (ManyToOne, JoinColumn `user_id`, CASCADE)
- `Uuid $stationId` → `RadioStationEntity $station` (ManyToOne, JoinColumn `station_id`, no onDelete)
- `getUserId()` / `getStationId()` preserved
- Added `#[ORM\Index(name: 'idx_starred_stations_user', columns: ['user_id'])]`

## Repositories changed
- All 4 repositories updated: constructor calls use `$this->entityManager->getReference(TargetEntity::class, $uuid)`, query fields changed from `'userId'`/`'sourceId'`/`'stationId'` to `'user'`/`'source'`/`'station'`
- RadioSessionDoctrineRepository `syncToEntity`: `setActiveStationId($state->activeStationId)` → `setActiveStation($em->getReference(...))`

## Validation
- `doctrine:schema:validate` → Mapping [OK] (no FAIL)
- `doctrine:schema:update --dump-sql` → 0 Radio-related drift statements remaining (down from ~12)
- PHP `-l` syntax check: all 8 files pass
- No tests reference these entities directly
- No external callers construct entities outside repositories

## Notes
- Doctrine will rename the FK constraints from custom names (e.g., `fk_country_subscriptions_user_id`) to hashed `FK_<hash>` names. This is expected — the parent migration will handle the rename.
- The `RadioSessionState.activeStationId` domain field remains `?Uuid`; the repository bridges between the domain UUID and the ORM relation reference.