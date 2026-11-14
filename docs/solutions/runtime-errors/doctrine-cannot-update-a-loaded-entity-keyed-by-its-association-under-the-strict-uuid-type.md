---
title: Doctrine cannot update a loaded entity keyed by its association under the strict UUID type
date: 2026-11-14
category: runtime-errors
module: Catalog album match states (AlbumMatchStateRepository); every entity whose primary key is a to-one association
problem_type: runtime_error
component: database
symptoms:
  - "EntityManager::flush() fails with InvalidArgumentException: Expected App\\Shared\\Domain\\Model\\Uuid or null, got string. thrown by UuidType::convertToDatabaseValue"
  - "Inserting the row works, and so does changing it again before the EntityManager is cleared"
  - "Changing the row after it was loaded from the database (find after a clear, or in a later request) fails on the UPDATE"
root_cause: wrong_api
resolution_type: code_fix
severity: medium
framework_version: doctrine orm 3.7.6, dbal 4.5.0
tags: [doctrine, unit-of-work, association-identifier, uuid-type, custom-type, dbal, upsert, repository]
---

# Doctrine cannot update a loaded entity keyed by its association under the strict UUID type

## Problem

`album_match_states` holds one row per album, so the album is its primary key. The
entity maps `album_id` as `#[ORM\Id] #[ORM\ManyToOne(targetEntity: AlbumEntity::class)]`,
the same shape as `PasswordResetTokenEntity` and `EmailVerificationTokenEntity`;
`UserLibraryAccessEntity` has one such association in its composite key. The first repository saved and replaced the
row through the unit of work. Replacing a row that had been loaded from the database
failed inside `flush()`, because Doctrine binds the loaded row's key as text and the
project's `uuid` type accepts only a `Uuid` object. The fix is on the branch
`feat/musicbrainz-matching` and is not merged yet.

## Symptoms

- `MusicBrainzMatchPersistenceTest::testAnAlbumMatchStateIsStoredReplacedAndRemoved`
  failed in the CI image with:

  ```text
  InvalidArgumentException: Expected App\Shared\Domain\Model\Uuid or null, got string.
  src/Shared/Infrastructure/Doctrine/Type/UuidType.php:44
  vendor/doctrine/orm/src/Persisters/Entity/BasicEntityPersister.php:496
  vendor/doctrine/orm/src/UnitOfWork.php:1228
  src/Catalog/Infrastructure/Doctrine/Repository/AlbumMatchStateRepository.php:43  (the flush in save())
  ```

- The first save of the row succeeded; the failure came on the second save, after the
  test had cleared the EntityManager and read the row back.
- Host unit tests and PHPStan passed. Only the PostgreSQL functional test, which clears
  the EntityManager between saves, reached the failing path.

## What Didn't Work

- **Following the existing association-keyed entities.** The repository was modelled on
  the `PasswordResetTokenEntity` mapping, but not on its repository. That repository
  never loads the entity: it writes the table with DBAL statements, and its docblock
  gives atomic replace and redeem as the reason. Nothing in the tree said the ORM path
  itself was broken, so copying the mapping and writing the repository the usual way
  looked safe.
- **Two alternatives considered and not tried.** Loosening `UuidType` to accept strings
  would weaken a deliberately strict shared type for every entity. Mapping `album_id` as
  a plain `uuid` column without the association would drop the foreign key from the
  mapping, while the migration still creates `fk_album_match_states_album_id`, so the
  schema tooling would see a difference.

## Solution

The repository stopped loading the entity. It reads and writes `album_match_states` with
single DBAL statements, as `src/Auth/Infrastructure/Repository/PasswordResetTokenRepository.php`
does, and `AlbumMatchStateEntity` stays only as the schema mapping:

```php
// src/Catalog/Infrastructure/Doctrine/Repository/AlbumMatchStateRepository.php
public function save(AlbumMatchState $state): void
{
    $this->connection->executeStatement(
        <<<'SQL'
            INSERT INTO album_match_states (album_id, state, source, due_at, reason, checked_song_count, checked_at, best_score)
            VALUES (:albumId, :state, :source, :dueAt, :reason, :checkedSongCount, :checkedAt, :bestScore)
            ON CONFLICT (album_id) DO UPDATE
               SET state = EXCLUDED.state,
                   ...
            SQL,
        ['albumId' => $state->albumId->toString(), /* ... */],
        ['dueAt' => Types::DATETIME_IMMUTABLE, 'checkedAt' => Types::DATETIME_IMMUTABLE],
    );
}
```

`findByAlbum()` is a `SELECT` that converts the instants with
`Connection::convertToPHPValue(..., Types::DATETIME_IMMUTABLE)` (the project's
`InstantType`, which keeps microseconds), and `removeByAlbum()` is a `DELETE`. The upsert
also removed a race the ORM version had: two first saves for one album at once no longer
collide on the primary key; the later one wins.

Verified in `baander-ci:u10`: `MusicBrainzMatchPersistenceTest` and
`MusicBrainzMatchMigrationTest` pass (23 tests), Deptrac reports 0 violations, and
`lint:container` passes.

## Why This Works

Doctrine ORM 3.7.6 keeps two different values as the identity of an association-keyed
entity, depending on how the entity entered the unit of work:

- **Persisted new.** `UnitOfWork::persistNew()` asks the assigned-ID generator for the
  identifier (`vendor/doctrine/orm/src/UnitOfWork.php:1005`). For an association field,
  `AssignedGenerator::generateId()` returns the target's own identifier value, here the
  album's `Uuid` object, and the unit of work stores it as is (`UnitOfWork.php:1016`).
- **Loaded from the database.** `UnitOfWork::createEntity()` builds the identity with
  `IdentifierFlattener::flattenIdentifier()` (`UnitOfWork.php:2484`). For an association
  key not given as an object, the flattener reads the raw join-column value from the row
  and stores `implode(' ', $associatedId)`, a string
  (`vendor/doctrine/orm/src/Utility/IdentifierFlattener.php:64-72`).

On an UPDATE, `BasicEntityPersister::updateTable()` binds that stored identity in the
WHERE clause with the type of the target's identifier field, here `uuid`
(`vendor/doctrine/orm/src/Persisters/Entity/BasicEntityPersister.php:438-460`). A string
reaches `UuidType::convertToDatabaseValue()`, which accepts only `null` or a `Uuid` and
throws otherwise (`src/Shared/Infrastructure/Doctrine/Type/UuidType.php:34-48`). Doctrine's
built-in key types (string, integer, guid) pass a string through, so with them the
flattened identity still binds.

DBAL statements never consult the unit of work's identity, so the type sees the value the
repository passes, `$albumId->toString()` bound as text.

## Prevention

- **Write association-keyed tables through DBAL.** For an entity whose `#[ORM\Id]` is a
  `ManyToOne` or `OneToOne`, give the repository `SELECT`, `INSERT ... ON CONFLICT ... DO
  UPDATE` and `DELETE` statements, keep the entity as mapping only, and say why in the
  repository's docblock. `AlbumMatchStateRepository` and the two Auth token repositories
  follow this.
- **Test the replace after a reload.** A test that saves and then saves again in the same
  EntityManager passes, because the persisted entity still holds the `Uuid` object. Clear
  the EntityManager (or use a fresh one) between the first save and the update, as
  `MusicBrainzMatchPersistenceTest` does, so the update runs against a loaded entity.
- **Expect removal to fail the same way.** The failure observed here was the UPDATE.
  `BasicEntityPersister::delete()` binds the same stored identity with the identifier's
  field types, `uuid` here (`BasicEntityPersister.php:558-570` and
  `getClassIdentifiersTypes()` at line 2038), so `EntityManager::remove()` on a loaded
  association-keyed entity reaches the same check. This was read from the source, not
  run.

## Related Issues

- [Doctrine refuses a flush after the database cascaded a delete under managed children](doctrine-refuses-a-flush-after-the-database-cascaded-a-delete-under-managed-children.md):
  another Doctrine ORM 3.7 unit-of-work failure where the ORM's bookkeeping, not the SQL,
  breaks a write. That fix stays in the ORM; this one leaves it.
- [Concurrent imports find or create catalog identities under sorted advisory locks](../architecture-patterns/concurrent-imports-find-or-create-identities-under-sorted-advisory-locks.md):
  rejects `INSERT ... ON CONFLICT` for identities split across several partial unique
  indexes. A single-column primary key, as here, is the case where the upsert does
  arbitrate.
