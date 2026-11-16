---
title: PGroonga 4.0's cleanup deletes indexes that uncommitted transactions build, and two cleanups collide over leftover objects
date: 2026-11-16
category: developer-experience
module: Backend test stacks and the database image (throwaway test schemas that build PGroonga indexes)
problem_type: test_failure
component: testing_framework
related_components: [database, infrastructure]
severity: high
symptoms:
  - "A test that builds its schema with FinalSchemaBootstrap fails on its first use of a table with a PGroonga index: SQLSTATE[22023]: Invalid parameter value: 7 ERROR:  pgroonga: PGrnLookupWithSize: object isn't found: <Sources50845>"
  - "The failure moves between tests and runs; the failing test passes alone and in most full runs"
  - "ANALYZE of a table with a PGroonga index fails with: pgroonga: failed to remove column: <Lexicon699191_0.index>: system call error: No such file or directory: failed to stat"
root_cause: concurrency
resolution_type: dependency_update
framework_version: pgroonga 4.0.5 on postgresql 18.4 (local) and 18.7 (CI); fixed in pgroonga 4.1.0
tags: [pgroonga, groonga, autovacuum, vacuum, analyze, uncommitted-index, cleanup-race, throwaway-schema, integration-tests, database-image]
---

# PGroonga 4.0's cleanup deletes indexes that uncommitted transactions build, and two cleanups collide over leftover objects

## Problem

Between 2026-10-22 and 2026-11-15, integration tests failed now and then with a PGroonga error on a table they had created moments before. Six failures hit five tests, four in local runs and two in step 41 of the GitHub CI quality gate. All five tests build a throwaway schema in `setUp` with `FinalSchemaBootstrap::bootstrap`, which replays all 28 migrations, the five PGroonga indexes among them, inside one transaction.

The cause is a defect in PGroonga 4.0.x ([upstream issue 1013](https://github.com/pgroonga/pgroonga/issues/1013)). The cleanup that `VACUUM`, `ANALYZE`, autovacuum and `pgroonga_vacuum()` run treats an index that another session has built and not yet committed as unused, and deletes its data. Test files in a lane run one at a time and none of the failing tests runs that cleanup, so autovacuum is the only session that can run it during a replay; when it did, the test's new indexes lost their data before the replay committed. A second, rarer failure comes from two cleanups colliding over the leftover objects of dropped indexes, and PGroonga 4.1.0 still has that one.

## Symptoms

| Date (UTC) | Where | Failing test | Statement | Error |
|---|---|---|---|---|
| 2026-10-22 16:08 | local run, `stack-2` | `PlaylistConcurrencyTest::testConcurrentReorderHandlersKeepTheWinnerAndRejectTheSameClientRevision` | `INSERT INTO albums` | `PGrnLookupWithSize: object isn't found: <Sources45789>` |
| 2026-11-07 14:27 | local run, `stack-2` | `LibraryDirectoryPublicationTest::testImmediateMissingConsumerObservationSurvivesAndUnchangedReturnIsPublished` | `SELECT count(*) FROM "songs"` | `<Sources375478>` |
| 2026-11-14 06:43 | local run, `stack-2` | `LibraryDirectoryPublicationTest::testHashReversionKeepsTheOriginalQueuedDiscoveryEligibleAndIdempotent` | `INSERT INTO songs` | `<Sources50845>` |
| 2026-11-15 15:52 | GitHub run 43695574134, third attempt, `stack-1` | `NewAlbumMetadataSyncTest::testIngestRequestsAMatchOfTheAlbumAScanCreatedOnceTheSettlingDelayPasses` | `INSERT INTO albums` | `<Sources53521>` |
| 2026-11-15 19:48 | local run, `stack-2` | `Library\LibraryDiscoveryObservationTest::testScannerFinishingBeforeTheFinalConsumerGuardSkipsOnlyCommittedReplacement` | `SELECT count(*) FROM movies`, then `INSERT INTO movies` | `<Sources49652>` |
| 2026-11-15 20:37 | GitHub run 43724380366, `stack-1` | `MusicBrainzMatchApplyTest`, known from the PostgreSQL log only: the same session next hit `chk_albums_test_country`, a constraint only that test adds | `INSERT INTO artists` | `<Sources1046940>` |

- Each failure hit an index that the failing test had just built in its own throwaway schema, with that schema first on its search path. The full error reads `SQLSTATE[22023]: Invalid parameter value: 7 ERROR:  pgroonga: PGrnLookupWithSize: object isn't found: <SourcesN>`.
- The tests passed alone and in most full runs. The retained local run artifacts held five PGroonga failures in 243 runs.
- The local runs used the runner's default database image, the local tag `baander-database:latest`. On the development host it was a Debian 12 build from 2026-05-21 with PostgreSQL 18.4, `libgroonga0` 16.0.4 and PGroonga 4.0.5-1. CI pulled `ghcr.io/baander-app/baander-postgres:latest`, a Debian 13 build with `libgroonga0` 16.1.5 and PGroonga 4.0.5-1. The two images share only PostgreSQL 18 and PGroonga 4.0.5.
- A different error appeared once. On 2026-11-15 at 14:17 a local run failed `CoveredIndexPersistenceTest::testTheWiderIndexesAloneServeTheDroppedIndexLookups` during `ANALYZE songs` on the `public` tables, with `pgroonga: failed to remove column: <Lexicon699191_0.index>: system call error: No such file or directory: failed to stat: <base/16384/pgrn.000054B>`. That cleanup met an object of a dropped index whose file another cleanup had already removed.

## What Didn't Work

- **Blaming dropped throwaway schemas.** The first version of this page, written on 2026-11-14 as "PGroonga indexes copied into throwaway test schemas can break PGroonga lookups in later suites", read the 2026-11-14 failure as damage left by the import race tests. Those tests had copied tables with their PGroonga indexes into throwaway schemas and dropped the schemas. The race fixtures switched to `ArtistImportIdentityRace::copyTableWithoutPgroonga`, and `LibraryDirectoryPublicationTest` then passed 41 of 41 in one run after `ArtistImportIdentityRaceTest`. That reading was wrong on three counts:
  - The first two failures, on 2026-10-22 and 2026-11-07, predate the race tests, which were added on 2026-11-14.
  - Every failure hit the failing test's own freshly built index, never an index in a dropped schema.
  - Dropping a schema breaks nothing by itself. On 2026-11-16, on both PGroonga 4.0.5 and 4.1.0, a throwaway schema with all five project tables that carry a PGroonga index (`albums`, `artists`, `genres`, `movies`, `songs`) was built in autocommit, written, searched and dropped. It left five `Sources` and five `Lexicon` objects behind, and a fresh connection's insert and index search on `public.songs` still succeeded, before and after `pgroonga_vacuum()` removed the leftovers.

  The 41-of-41 pass was a single run of a failure that struck about two runs in a hundred, so it proved nothing. The drops do matter, but only to the second failure: their leftover objects are what two cleanups collide over.
- **Turning autovacuum off in the test stacks (considered, not built).** It would close both failures in tests without an upgrade. It would also make the test databases differ from production, let the catalogs bloat over about 500 schema replays per run, and fix nothing for a migration that builds a PGroonga index outside the tests.
- **A guard on `DROP SCHEMA` (considered, not built).** Dropping a schema is not the trigger. A new cleanup statement in a test is what can bring a failure back, so the guard watches those instead.

## Solution

1. **PGroonga 4.1.0-1 in the database image.** `docker/postgres/Dockerfile` sets `ARG PGROONGA_VERSION=4.1.0-1`, and `docker-compose.yml` passes the same build argument. The 4.0.5 to 4.1.0 upgrade is compatible on PGroonga's upgrade table, and its extension scripts add only `pgroonga_physical_table_names()`, which the application does not call, so no migration runs the update. A database created on the new image starts at `extversion` 4.1.0. A retained database keeps the version it recorded until its owner runs `ALTER EXTENSION pgroonga UPDATE`.
2. **A test clears leftover objects before it runs a cleanup.** `PgroongaLeftovers::clear()` (`tests/Support/Doctrine/PgroongaLeftovers.php`) runs inside `Connection::transactional()`: its own transaction in autocommit, a savepoint inside a caller's transaction. It lists every table with a PGroonga index in every schema, in OID order, locks them all with one `LOCK TABLE ... IN SHARE UPDATE EXCLUSIVE MODE`, and runs `pgroonga_vacuum()`. `CoveredIndexPersistenceTest` and `tests/Fixtures/Media/image-read-scope-plan.php` call it before their `ANALYZE`:

   ```php
   PgroongaLeftovers::clear($connection);
   foreach (array_unique(array_column(self::COVERED, 0)) as $table) {
       $connection->executeStatement('ANALYZE ' . $table);
   }
   ```

3. **A regression test and a version floor.** `tests/Integration/PgroongaUncommittedIndexTest.php` has one session build a PGroonga index in an open transaction while a second session runs `ANALYZE` of a committed PGroonga table, `VACUUM` or `pgroonga_vacuum()`; the first then commits, inserts and searches. The order is fixed by the test, so it fails every time on a PGroonga that deletes uncommitted indexes. `PgroongaSearchCompatibilityTest::testInstalledExtensionKeepsTheIndexesOfUncommittedTransactions` requires `extversion` 4.1.0 or later and names the fix in its failure message. It used to require 2.0.4. The messaging gate runs that test too (`packages/baander-tooling/src/Fixtures/Messaging/Qualification.php`), so the gate also fails on an older image.
4. **A guard on cleanup statements.** `tests/Unit/Shared/Infrastructure/Doctrine/PgroongaCleanupConventionTest.php`, with `tests/Support/Doctrine/PgroongaCleanupScanner.php`, fails when a PHP file under `tests/` holds an `ANALYZE` or `VACUUM` string literal at a statement start, or a `pgroonga_vacuum(` call, unless the file calls the helper or is on the guard's reviewed list with an exact count and a reason. The list holds `UserEmailLookupTest`, `RetagLockOrderTest`, `playback-snapshot-cost.php` and the guard's own fixture; none of their cleanups reaches a table with a PGroonga index.
5. **Throwaway schemas are built as before.** `FinalSchemaBootstrap` keeps its single transaction and its PGroonga indexes, so the migration tests still verify the real migrations. The race fixtures keep `copyTableWithoutPgroonga`, since they need only the unique and B-tree indexes. It was not the cure.

Verification on 2026-11-16, with images built that day from the same Dockerfile and differing only in the PGroonga pin (Debian 13.7, `postgresql-18` 18.7-1.pgdg13+2, Groonga 16.1.5):

- On PGroonga 4.0.5, in one lane in a fixed order, the three `PgroongaUncommittedIndexTest` cases failed with `object isn't found: <Sources19906>`, `<Sources19927>` and `<Sources19948>`. The version floor failed with its 4.1.0 message, and `PgroongaLeftoversTest::testClearKeepsTheObjectsOfAnIndexAnotherSessionHasNotCommitted` failed, because on 4.0.5 the helper's own cleanup deletes the uncommitted index. The 14 other cases passed afterwards in the same lane and database.
- On 4.1.0, the same 19 tests passed with none skipped, and `image-read-scope-plan.php` ran to completion.
- Five full backend runs on 4.1.0 with the helper and the guard, 13,028 tests each, on branch commit `dfbd5684d`, all passed, with no `pgroonga:` ERROR line in any lane's PostgreSQL log:

| Seed | ordinary | native | stack-1 | stack-2 |
|---|---|---|---|---|
| 20261116 | 98.7 s | 344.9 s | 1,420.0 s | 1,382.3 s |
| 7341 | 89.6 s | 341.4 s | 1,408.3 s | 1,360.1 s |
| 99881 | 94.8 s | 340.3 s | 1,409.3 s | 1,376.0 s |
| 424242 | 95.9 s | 341.0 s | 1,390.3 s | 1,377.0 s |
| 31337 | 93.2 s | 340.9 s | 1,403.3 s | 1,370.5 s |

## Why This Works

A PGroonga index keeps its data in Groonga objects named after the index's relfilenode (`Sources<N>`, `Lexicon<N>_<n>` and their columns). They live in one Groonga store per database, outside PostgreSQL's catalog and transactions. PGroonga has no drop hook, so `DROP INDEX`, `DROP TABLE`, `DROP SCHEMA ... CASCADE` and a rolled-back `CREATE INDEX` all leave the objects behind.

`PGrnRemoveUnusedTables` is the only code that removes them. It runs in every `VACUUM` and `ANALYZE` of a table with a PGroonga index (an `ANALYZE` calls index cleanup with `analyze_only`), in autovacuum and autoanalyze of such a table, and in `pgroonga_vacuum()`. It scans every `Sources*` object in the database and removes the objects of each relfilenode that resolves to no relation.

In 4.0.5 that check runs under the cleaning session's snapshot. An index that another session has built and not committed is invisible there, so it counts as unused and loses its objects. When the building transaction commits, the index exists in the catalog with no data behind it and answers "object isn't found" until `REINDEX`. PGroonga 4.1.0 ([PR 1014](https://github.com/pgroonga/pgroonga/pull/1014), released 2026-10-09) re-checks a miss against `pg_class` with a dirty snapshot, which sees uncommitted rows.

The replay in `FinalSchemaBootstrap` is exactly such a building transaction, and it stays open for seconds. An open transaction pins dead tuples, so a table stays due for autovacuum, and autovacuum processes it again every naptime while the replay runs. Inside a stack lane, test files run one at a time, and neither the failing tests nor `src/` run `ANALYZE` or `VACUUM`, which leaves autovacuum as the session that ran the cleanup. That is an inference by elimination: those runs kept `log_autovacuum_min_duration` at its default, so their logs hold no autovacuum lines. A diagnostic full run on 4.0.5 with `log_autovacuum_min_duration = 0` (seed 41160) counted 27 autovacuum runs on PGroonga-indexed `public` tables in one stack lane and 38 in the other, each of them a cleanup. That run hit no PGroonga error: the cleanup has to land inside a replay's window. `PgroongaSearchCompatibilityTest` also builds and drops a PGroonga index in a throwaway schema, and it never failed: Doctrine ORM 3.7.6's `SchemaTool::createSchema` runs each statement in autocommit, so its window is one statement long.

Disposable containers of the two builds, on initdb defaults, showed both halves of this:

- With autovacuum off, session A built a table and a PGroonga index in an open transaction, session B ran `ANALYZE` of a committed PGroonga table, `VACUUM` or `pgroonga_vacuum()`, and A then committed and inserted. On 4.0.5 all three orders failed with `22023 pgroonga: PGrnLookupWithSize: object isn't found: <SourcesN>`. `EXPLAIN` of a search and `pgroonga_list_broken_indexes()` raised the same error, and `REINDEX INDEX` repaired the index. On 4.1.0 all three passed, and `EXPLAIN` of the search showed an `Index Only Scan` through the PGroonga index with its `Index Cond`.
- With autovacuum alone (naptime 1 s, A holding its transaction for 10 s), an autovacuum worker processed `songs` 1.3 s after the churn on 4.0.5, and A's insert after its commit failed with `object isn't found`. On 4.1.0, A's objects survived ten autovacuum runs of `songs` inside its window.

The collision is a separate defect. Nothing serializes two cleanups, and the removal loop is the same in 4.0.5, 4.1.0 and upstream `main`. In [issue 252](https://github.com/pgroonga/pgroonga/issues/252) (2022) a maintainer suspected concurrent `VACUUM` and proposed exclusion, which never landed. With autovacuum off, 50 dropped schemas and two sessions released into `pgroonga_vacuum()` at once, all 30 iterations collided on each version. Exactly one cleanup failed each time: on 4.0.5 always with 58030 `failed to remove column: <Lexicon<N>_0.index>: ... failed to stat`, and on 4.1.0 that way 28 times and twice with 22023 `failed to remove: <SourcesN>: [object][remove][force] nonexistent object`. The other cleanup finished, nothing was left, and a cleanup run alone afterwards succeeded. Collisions converge, but the error reaches whatever statement ran the losing cleanup, such as a test's `ANALYZE`.

The helper makes the test's cleanup the only one in the database. `SHARE UPDATE EXCLUSIVE` conflicts with itself, and every autovacuum run except one against wraparound carries `VACOPT_SKIP_LOCKED`, so it skips a table it cannot lock at once instead of waiting. When a worker already holds the lock, PostgreSQL cancels it (57014 `canceling autovacuum task`) once the helper has waited `deadlock_timeout`. The cleanup functions contain no `CHECK_FOR_INTERRUPTS` while `pgroonga.enable_wal` is off, as it is in the test stacks, so the cancel cannot land partway through the cleanup itself. The helper's `pgroonga_vacuum()` then removes every leftover, and its locks last until the caller's transaction ends, so the caller's own `ANALYZE` finds nothing left to collide over. No retry is needed, and no absorbed error lands in the server log. On 4.1.0 with naptime 1 s:

- holding the locks on every PGroonga table for 15 s produced 28 `55P03` `skipping vacuum of "<table>" --- lock not available` lines (14 for `songs`, 14 for `albums`), left the autovacuum counters unchanged, and workers processed the tables within 1.3 s of the release;
- in 20 iterations with 50 leftovers present, the helper requested its locks while autovacuum processed `songs`. Every worker was cancelled, the helper waited exactly `deadlock_timeout` (1 s by default), and its `pgroonga_vacuum()` raised no error and left no orphans.

## Prevention

- Keep PGroonga at 4.1.0 or later. The version floor in `PgroongaSearchCompatibilityTest` fails a run on an older image with a message that names 4.1.0. The local default test image is not rebuilt automatically: rebuild `baander-database:latest` after a change to `docker/postgres/Dockerfile`, or name a current build in `BAANDER_TEST_POSTGRES_IMAGE`, as `docs-book/part-2-developer-guide/testing/backend-runners.md` describes.
- Before a test runs `ANALYZE` or `VACUUM` on a table with a PGroonga index, or `pgroonga_vacuum()`, call `PgroongaLeftovers::clear()` in the same transaction. `PgroongaCleanupConventionTest` fails otherwise. Review a file into its list only when its cleanups never run or reach no table with a PGroonga index.
- A retained database that starts on a newer image keeps its old `extversion`. Run `ALTER EXTENSION pgroonga UPDATE` as the database owner over a direct connection before its next migration; a development instance's checkpoint rehearsal blocks the migration until then (`docs-book/part-2-developer-guide/getting-started/development-environment.md`).
- When a test fails with `object isn't found`, check `extversion` first, then whether the index was built in a transaction that stayed open while a cleanup ran. `REINDEX INDEX` repairs the index. On 4.0.5, `pgroonga_list_broken_indexes()` raises that same error instead of listing the index; its behaviour with a broken index on 4.1.0 is untested.
- Classify `pgroonga:` errors by message, not SQLSTATE. 22023 `object isn't found` is a lost index. 58030 `failed to remove column ... failed to stat`, and on 4.1.0 22023 `failed to remove ... nonexistent object`, is a collision. When scanning a run's PostgreSQL logs, expect the helper test's `55P03` lines, which name `pgroonga_leftovers_<hex>` schemas and so match a loose `pgroonga` search, `57014 canceling autovacuum task` where the helper waited for a worker, and `55P03 skipping vacuum` lines when autovacuum logging is on.

## Related Issues

- [PGroonga issue 1013](https://github.com/pgroonga/pgroonga/issues/1013) and [PR 1014](https://github.com/pgroonga/pgroonga/pull/1014): the uncommitted-index defect and its fix in 4.1.0. [Issue 252](https://github.com/pgroonga/pgroonga/issues/252): the unserialized cleanup.
- `.agents/skills/postgres-remediation/references/extensions.md`: the PGroonga inventory, with the cleanup, the test databases' configuration and the stale default test image.
- `docs-book/part-2-developer-guide/testing/conventions.md`: the cleanup rule for tests.
- `docs/solutions/architecture-patterns/concurrent-imports-find-or-create-identities-under-sorted-advisory-locks.md`: the import race tests and how they run a second session; their fixtures copy tables without PGroonga indexes.
- `docs/solutions/best-practices/debian-13-base-images-change-collation-time-zone-links-and-vaapi-limits.md`: the previous database image change and how an existing data volume was moved.
