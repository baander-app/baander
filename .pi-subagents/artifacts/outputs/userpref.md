# UserPreference @ManyToOne Refactor — Complete

## Summary
Refactored all 7 UserPreference entities to replace loose `Uuid $userId` columns with proper `@ManyToOne` relations to `UserEntity`. Updated all 7 corresponding repositories to use `getReference()` and `['user' => $uuid]` query keys.

## Pattern Applied (identical across all 7)

### Entity changes:
- Replaced `#[ORM\Column(type: 'uuid')] private Uuid $userId` with:
  ```php
  #[ORM\ManyToOne(targetEntity: UserEntity::class)]
  #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
  private UserEntity $user;
  ```
- Changed `getUserId()` to return `$this->user->getId()` (preserves domain API)
- Replaced `setUserId(Uuid)` with `setUser(UserEntity)` + added `getUser()` accessor
- Added `use App\Auth\Infrastructure\Doctrine\Entity\UserEntity`

### Repository changes:
- Changed `['userId' => $uuid]` → `['user' => $uuid]` in all findOneBy/findBy queries
- Changed `$entity->setUserId($uuid)` → `$entity->setUser($this->entityManager->getReference(UserEntity::class, $uuid))`
- Added `use App\Auth\Infrastructure\Doctrine\Entity\UserEntity`

## Files Changed

### Entities (7):
1. `src/UserPreference/Infrastructure/Doctrine/Entity/AudioPreferencesEntity.php`
2. `src/UserPreference/Infrastructure/Doctrine/Entity/EqDeviceProfileEntity.php`
3. `src/UserPreference/Infrastructure/Doctrine/Entity/LayoutPreferencesEntity.php`
4. `src/UserPreference/Infrastructure/Doctrine/Entity/PlayerPreferencesEntity.php`
5. `src/UserPreference/Infrastructure/Doctrine/Entity/PreferenceHistoryEntity.php`
6. `src/UserPreference/Infrastructure/Doctrine/Entity/SidebarConfigEntity.php`
7. `src/UserPreference/Infrastructure/Doctrine/Entity/UserAccentColorEntity.php`

### Repositories (7):
1. `src/UserPreference/Infrastructure/Doctrine/Repository/AudioPreferencesDoctrineRepository.php`
2. `src/UserPreference/Infrastructure/Doctrine/Repository/EqDeviceProfileDoctrineRepository.php`
3. `src/UserPreference/Infrastructure/Doctrine/Repository/LayoutPreferencesDoctrineRepository.php`
4. `src/UserPreference/Infrastructure/Doctrine/Repository/PlayerPreferencesDoctrineRepository.php`
5. `src/UserPreference/Infrastructure/Doctrine/Repository/PreferenceHistoryDoctrineRepository.php`
6. `src/UserPreference/Infrastructure/Doctrine/Repository/SidebarConfigDoctrineRepository.php`
7. `src/UserPreference/Infrastructure/Doctrine/Repository/AccentColorDoctrineRepository.php`

## Validation
- `doctrine:schema:validate` Mapping: **[OK]**
- FK constraint drift for all 7 tables: **eliminated** (0 DROP CONSTRAINT statements)
- `preference_history` drift: **0 statements** (composite index covers user_id)
- No external callers found outside UserPreference namespace

## Residual Notes
- `user_sidebar_configs` has a pre-existing unique index `user_sidebar_configs_user_id_key` on just `user_id` that the entity declares as `uniq_user_media` on `(user_id, media_type)`. This will be handled by the migration (drop extra unique, let Doctrine create its FK index).
- Doctrine will rename FK constraints from custom `fk_*_user_id` names to hashed `FK_<hash>` names — expected and acceptable per the reference pattern.
- `UserThemeMoodEntity` also has `userId` but was NOT in scope for this task — left unchanged.
