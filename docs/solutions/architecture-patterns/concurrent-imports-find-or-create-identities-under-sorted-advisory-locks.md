---
title: Concurrent imports find or create catalog identities under sorted advisory locks
date: 2026-11-14
last_updated: 2026-11-14
category: architecture-patterns
module: Catalog import identities (artists, genres, album credits)
problem_type: architecture_pattern
component: database
related_components: [background_job]
severity: high
applies_when:
  - Two Messenger workers or an import and a console command can find or create the same row at once
  - The row's identity is a name, a slug or an external ID that no single unique key arbitrates
  - Code is tempted to catch UniqueConstraintViolationException after a Doctrine flush and read the winner
  - A decision about a parent row (such as crediting an album) must not run twice while children are inserted under it
  - A test must prove which locks a session takes, and in which order
symptoms:
  - "GenreRepository::findOrCreateByName caught the unique violation after a failed flush, but the flush had already closed the entity manager, so the whole import batch failed"
  - "The library scan created no artists at all; the fix had to create them from tags in batches that run in parallel workers"
root_cause: concurrency
tags: [advisory-lock, find-or-create, doctrine, entity-manager-closed, read-committed, for-no-key-update, import, deadlock]
---

# Concurrent imports find or create catalog identities under sorted advisory locks

## Context

The library scan imports each directory as its own Messenger message, and several workers run those messages at once. Each batch of 50 songs must find or create the artists its tags name and the genres they list. Until branch `fix/scan-artist-credits`, the scan created no artists at all, so lyrics lookups had no artist to send. The fix had to create artists from tags without two workers creating the same artist twice.

The obvious tools fail here, each for a reason that is not visible in the final code:

- **Catch the unique violation after the flush.** `GenreRepository::findOrCreateByName` did this before the fix: it persisted, flushed, caught `UniqueConstraintViolationException`, cleared and read the winner by slug. In Doctrine ORM 3.7.6 a flush that fails closes the entity manager before the exception reaches the caller (`vendor/doctrine/orm/src/UnitOfWork.php:470-472`: `if (! $successful) { $this->em->close(); ...`). The catch block then works on a closed manager, and the import's whole batch rolls back. Reading source and vendor code found the defect, and `tests/Integration/GenreImportRaceTest.php` was written on branch `fix/scan-artist-credits` to reproduce it before the fix.
- **A unique index with `INSERT ... ON CONFLICT`.** An artist is identified by its MusicBrainz ID when the file carries exactly one, else by its exact name. One import can create "H" with no ID while another creates "H" with an ID. The two cases need different partial unique indexes, so neither insert conflicts with the other and two artists result. A unique index on the name alone would also forbid same-name artists, which users create by hand.
- **Locking per song as songs are written.** Two batches that meet artists in a different order hold one lock each and wait on the other's, and PostgreSQL resolves the deadlock by failing one batch.

## Guidance

Use transaction-scoped advisory locks, keyed by the identity itself and taken before the transaction writes anything, to serialize every find-or-create that no unique key arbitrates.

1. **Lock every key a batch touches, sorted, one statement per key.** `ImportIdentityLock::acquire` (`src/Catalog/Infrastructure/Doctrine/ImportIdentity/ImportIdentityLock.php:39`) runs `SELECT pg_advisory_xact_lock(hashtextextended(:key, 20261114120000))` for each key in byte order. The keys are `artist-name:<name>` for every credit, `artist-mbid:<id>` when the credit carries exactly one ID, and `genre:<slug>` for every genre. The genre key is the slug, not the name, because "Hip-Hop" and "Hip Hop" share the slug that `uniq_genres_slug` enforces. The hash seed keeps these locks apart from the library import lock, which uses `IMPORT_LOCK_SEED` (`src/Library/Infrastructure/Doctrine/Repository/LibraryRepository.php:33`).
2. **Refuse to lock outside a transaction.** A transaction-scoped lock taken in autocommit ends with its own statement, so it serializes nothing. `acquire` throws `LogicException` when `isTransactionActive()` is false (`ImportIdentityLock.php:42`). This mattered for the artist metadata enricher, which ran without a transaction and assigned MusicBrainz IDs to artists. It now assigns through `ArtistService::assignMusicbrainzId`, which wraps `ArtistMusicbrainzIdAssigner::assign` in a transaction (`src/Catalog/Infrastructure/ArtistService.php:91`) that takes the same name and ID keys (`ArtistMusicbrainzIdAssigner.php:42-45`).
3. **Read again in a statement of its own after the locks.** PostgreSQL's default isolation, READ COMMITTED, takes a snapshot per statement. A lookup that runs after `pg_advisory_xact_lock` returns sees the artist the lock's previous holder committed; a lookup in the same statement as the lock, or under REPEATABLE READ, does not. `ArtistIdentityResolver::storedArtists` also sets `Query::HINT_REFRESH` (`ArtistIdentityResolver.php:96`), so an artist the unit of work still holds from an earlier batch is replaced by its committed state. The race tests assert `SHOW default_transaction_isolation` is `read committed` before they run, because the design depends on it.
4. **Resolve every identity of the batch before writing any song.** Doctrine's finders do not see rows persisted but not yet flushed, so a batch that resolved per song would create an artist per song. `ArtistIdentityResolver::resolve` (`ArtistIdentityResolver.php:26-47`) locks, reads and resolves the batch's distinct identities in one call and returns a map from identity key to artist ID; `GenreRepository::findOrCreateForImport` does the same for genres. Locking all keys up front also keeps the lock order acyclic.
5. **Order separate calls by key prefix.** Sorting covers the keys of one call. A transaction that calls `acquire` twice is deadlock-free only if every key of the second call sorts after every key of the first. The import resolves artists before genres, and `artist-` sorts before `genre:` (`src/Catalog/Application/CommandHandler/FilesDiscoveredHandler.php:628`). A new identity kind needs a prefix that sorts after every key locked before it in the same transaction. A key the transaction already holds is granted again at once, since PostgreSQL's advisory locks count repeated takes by one session. A transaction may therefore take every key of the batch first and still call the resolver and the genre lookup afterwards: the scan does this for retagged songs, whose rows it must lock after the identity keys (`SongService::lockForRetag`).
6. **Lock a parent row with `FOR NO KEY UPDATE` when only its deciders must take turns.** Crediting an album runs once per message after its songs are written. Two messages for one album must not both credit it, but song inserts into the album must not wait. `AlbumRepository::lockForArtistCredit` (`src/Catalog/Infrastructure/Doctrine/Repository/AlbumRepository.php:446`) runs `SELECT id FROM albums WHERE id = :id FOR NO KEY UPDATE` in SQL, since DQL offers only `FOR UPDATE` and `FOR SHARE`. A song's foreign key to the album takes `FOR KEY SHARE` on the album row, which `FOR UPDATE` blocks and `FOR NO KEY UPDATE` does not. The checks after the lock again run as statements of their own.

## Why This Matters

Apart from the name-only unique index, which forbids same-name artists in any case, every alternative above fails only under concurrency, so single-worker tests pass. A scan of a large library runs several workers, and an artist or genre shared across directories is the normal case, not an edge case. The caught-violation pattern is the most dangerous because it reads as correct: the catch block is there, and nothing shows that the manager it uses is already closed.

The advisory lock also gives the one property a unique index cannot: it serializes identities that live on different indexes, such as a name-only artist and a same-name artist with an ID, because both take `artist-name:<name>`.

## When to Apply

- A new find-or-create runs inside the import or any other code that parallel workers execute, for any identity without a natural unique key: labels, album artists, composers, tags.
- A unique key exists but the code would rely on catching its violation after a flush.
- A change adds another `ImportIdentityLock::acquire` call to a transaction that already takes identity locks: check the key-prefix order of point 5.
- A decision about a parent row runs in parallel with inserts of its children: use `FOR NO KEY UPDATE`, not `FOR UPDATE`.
- A test checks which locks a session holds: observe them while the session waits, as the last example shows.

Outside the parallel import, `GenreRepository::findOrCreateByName` (`GenreRepository.php:305`) still persists and flushes with no lock and no catch. Its callers, the metadata enrichers and `GenreService`, create one genre at a time, so a collision fails that call loudly on `uniq_genres_slug` instead of being caught; move a caller to `findOrCreateForImport` if it starts creating genres in parallel batches.

## Examples

Before, the genre repository relied on a caught violation (removed on branch `fix/scan-artist-credits`):

```php
$this->entityManager->persist($entity);
try {
    $this->entityManager->flush();
} catch (UniqueConstraintViolationException $e) {
    $this->entityManager->clear();      // the manager is already closed here
    $existing = $this->findBySlug($slug);
    ...
}
```

After, the batch locks its genre slugs, reads, and creates only what is missing (`GenreRepository.php:318`):

```php
ImportIdentityLock::acquire(
    $this->entityManager->getConnection(),
    array_map(fn (string $name): string => ImportIdentityLock::genreKey($this->generateSlug($name)), $names),
);
foreach ($names as $name) {
    $slug = $this->generateSlug($name);
    // The finders do not see a genre persisted earlier in this call.
    $genre = $genres[$slug] ?? $this->findTaggedGenre($name, $slug);
    ...
}
```

The race tests show how to prove such a design: the test session holds a transaction open after locking, a child PHP process runs the competing import, the test waits until `pg_locks` shows the child waiting, then commits and checks that one row results. See `tests/Integration/ArtistImportIdentityRaceTest.php`, `GenreImportRaceTest.php` and `AlbumCreditRaceTest.php`, with helpers in `tests/Fixtures/Catalog/ArtistImportIdentityRace.php`. `AlbumCreditRaceTest::testASongAddedToTheAlbumDoesNotWaitForItsCreditDecision` pins the `FOR NO KEY UPDATE` choice.

A test of lock *order* has to look at the locks while the session still waits for one, never after its wait timed out. When a statement fails inside a PostgreSQL transaction block, the server aborts the transaction on the spot and releases every lock it held, advisory transaction locks and row locks alike; the block then only waits for the `ROLLBACK`. A session that sets `lock_timeout`, times out with SQLSTATE 55P03 and is then inspected therefore holds nothing. The first version of `tests/Integration/RetagLockOrderTest.php` did exactly that and read zero advisory locks and no row locks in both of its cases. The test now runs the locking code in a child process with its own session (`tests/Fixtures/Catalog/RetagLockRace.php`, child script `tests/Fixtures/Catalog/retag-lock.php`). It polls `pg_locks` from an observer session until the child has a lock with `granted = false`, then counts the child's granted advisory locks by its `pid` and probes its rows with `FOR NO KEY UPDATE NOWAIT`, and only then releases the holder and lets the child commit. To make the ID-order case able to fail, it inserts the songs against their ID order, runs `ANALYZE`, and turns index scans off in the child, so a lock without `ORDER BY id` would wait on the wrong row first.

## Related

- `docs/solutions/runtime-errors/doctrine-refuses-a-flush-after-the-database-cascaded-a-delete-under-managed-children.md`: another way Doctrine ORM 3.7.6 unit-of-work state breaks batch work.
- `CONCEPTS.md`, "Artist credit": the resolution order the locks protect.
