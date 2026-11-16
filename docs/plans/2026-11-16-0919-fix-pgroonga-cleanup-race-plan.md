---
title: PGroonga Cleanup Race - Plan
type: fix
date: 2026-11-16
topic: pgroonga-cleanup-race
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# PGroonga Cleanup Race - Plan

## Goal Capsule

- **Objective:** A full backend run passes or fails on the code under test, whatever the test order and whenever PostgreSQL's autovacuum runs, locally and in step 41 of the CI quality gate. A PGroonga search index that a migration or a test builds keeps answering after its transaction commits.
- **Product authority:** `ROADMAP.md`: the "Next" line, where the user chose "PGroonga isolation of throwaway test schemas" on 2026-11-16, the open items under "Master CI repair", the `AlbumMatchClaimMigrationTest` item under "Matching follow-ups", and the housekeeping item under "Scan artist credits, song lengths and lyrics outcomes". The user decided to leave the backend step's 3,600-second budget alone until a run without the PGroonga failure shows the real duration.
- **Means:** PGroonga 4.1.0 in the database image (KTD1). A test session that runs a PGroonga cleanup clears leftover Groonga objects first (KTD2). A deterministic two-session regression test and a guard on cleanup statements in tests (KTD3, KTD4). The persistent development instance moves to the new image with its data (KTD7).
- **Execution profile:** One branch named after this plan. U1 to U6 land as local commits in that order; U7 runs on the persistent instance after the push and commits nothing but its roadmap record. U1, U3, U4, the database steps of U7 and the inventory part of U6 go to the postgres specialist (`.agents/agents/postgres.md`), the extension inventory's one writer. The plan holds no migration and no change to `packages/baander-tooling`.
- **Authority and stop conditions:** Follow `AGENTS.md`, then the Product Contract, then the Planning Contract. Stop and report to the user when any of these happens:
  - probe P1 (U1) does not fail on PGroonga 4.0.5 or does not pass on 4.1.0;
  - the negative control P0 fails, which would mean dropping a schema breaks later lookups after all;
  - probe P3 shows that a collision leaves objects that a later cleanup, run alone, cannot remove; or probe P4 shows autovacuum processing a locked table, or the helper's cleanup failing or leaving orphans after it cancelled a worker. The fallback is then autovacuum off in the test stacks, for the user to confirm;
  - a full run after U5 shows a PGroonga error raised in a test session;
  - before U7 removes the instance's 4.0.5 image, the instance is not ready on remote `master`'s revision, or the rehearsal on its volume copy fails;
  - U7's dump does not restore cleanly or the restored row counts differ from those of its snapshot, or the move needs anything destructive: a data reset, a removed volume, a migration rollback, or a repair that `make dev-up` asks for.
- **Finishing:** `ce-work` implements U1 to U6, `ce-simplify-code` runs after U5, `ce-code-review` runs before shipping and its justified findings are applied. Push only when the user asks, in the order OQ3 gives; U7 follows the push.

---

## Product Contract

### Summary

PGroonga's cleanup runs whenever PostgreSQL vacuums or analyzes a table that has a PGroonga index, and in PGroonga 4.0.5 it deletes the search data of an index whose creating transaction has not committed yet. Tests that build their own schema replay every migration in one transaction, so an autovacuum that lands during the replay leaves the test with an index whose data is gone. This plan moves the database image to PGroonga 4.1.0, which fixes that check. Every test session that runs a cleanup first clears leftover objects, so it has nothing to race autovacuum over. A regression test and a guard keep both properties. The persistent development instance moves to the new image with its data. The extension inventory, the solution page and the roadmap get the corrected cause.

### Problem Frame

The roadmap, the solution page `docs/solutions/developer-experience/pgroonga-indexes-in-throwaway-test-schemas-break-later-suites.md` and this item's name attribute the failure to tests that create PGroonga indexes in a throwaway schema and drop it before a later suite. The evidence does not support that. Every "object isn't found" failure on record hit an index that the failing test had itself just built in its own throwaway schema, with that schema first on its search path:

| Date (UTC) | Where | Failing test | Statement | Error |
|---|---|---|---|---|
| 2026-10-22 16:08 | local run, `stack-2` | `PlaylistConcurrencyTest::testConcurrentReorderHandlersKeepTheWinnerAndRejectTheSameClientRevision` | `INSERT INTO albums` | `PGrnLookupWithSize: object isn't found: <Sources45789>` |
| 2026-11-07 14:27 | local run, `stack-2` | `LibraryDirectoryPublicationTest::testImmediateMissingConsumerObservationSurvivesAndUnchangedReturnIsPublished` ("music unavailable") | `SELECT count(*) FROM "songs"` | `<Sources375478>` |
| 2026-11-14 06:43 | local run, `stack-2` | `LibraryDirectoryPublicationTest::testHashReversionKeepsTheOriginalQueuedDiscoveryEligibleAndIdempotent` | `INSERT INTO songs` | `<Sources50845>` |
| 2026-11-15 15:52 | GitHub run 43695574134, third attempt, `stack-1` | `NewAlbumMetadataSyncTest::testIngestRequestsAMatchOfTheAlbumAScanCreatedOnceTheSettlingDelayPasses` | `INSERT INTO albums` | `<Sources53521>` |
| 2026-11-15 19:48 | local run, `stack-2` | `Library\LibraryDiscoveryObservationTest::testScannerFinishingBeforeTheFinalConsumerGuardSkipsOnlyCommittedReplacement` ("committed replacement") | `SELECT count(*) FROM movies`, then `INSERT INTO movies` | `<Sources49652>` |
| 2026-11-15 20:37 | GitHub run 43724380366, `stack-1` (lane output lost; PostgreSQL log only) | `MusicBrainzMatchApplyTest`: the same session next hit `chk_albums_test_country`, a constraint only that test adds | `INSERT INTO artists` | `<Sources1046940>` |

All five tests build their schema in `setUp` with `FinalSchemaBootstrap::bootstrap`, which replays all 28 migrations, the five PGroonga indexes among them, inside one transaction (`tests/Support/FinalSchemaBootstrap.php`). Each adopted it before its first failure, between 2026-10-17 and 2026-11-14. The first two failures predate the race tests (added 2026-11-14) that the solution page blames.

A second symptom differs. On 2026-11-15 at 14:17 a local run failed `CoveredIndexPersistenceTest::testTheWiderIndexesAloneServeTheDroppedIndexLookups` with `pgroonga: failed to remove column: <Lexicon699191_0.index>: system call error: No such file or directory: failed to stat: <base/16384/pgrn.000054B>` during `ANALYZE songs`, on public tables. The cleanup that `ANALYZE` runs met an object of a dropped index whose file was already gone. Here earlier schema drops do matter: they leave the objects that two cleanups can race over.

The local runs used the test runner's default image, `baander-database:latest`. On this host that tag is a build from 2026-05-21 on Debian 12, with PostgreSQL 18.4, `libgroonga0` 16.0.4 and PGroonga 4.0.5-1, which no longer matches `docker/postgres/Dockerfile`. CI pulls `ghcr.io/baander-app/baander-postgres:latest`, a Debian 13 build; its log shows PostgreSQL 18.7, and the local copy of that image from 11 days earlier holds `libgroonga0` 16.1.5 and PGroonga 4.0.5-1. The failure appears on both images, which differ in Debian release and Groonga and share only PostgreSQL 18 and PGroonga 4.0.5.

The two GitHub runs that reached step 41 since 2026-11-15 both hit the first symptom. The retained local artifacts hold five PGroonga failures in 243 runs with two stack lanes, most of them targeted selections. The run on `8d119f5df` also ran out of the step's 3,600-second budget with every lane about 1.6 times slower than the third attempt before it, including the ordinary lane, which runs no database (248 s against 153). That slowdown is the runner's, not PGroonga's; the budget stays as the user decided.

### Requirements

**Mechanism**

- R1. A deterministic reproduction on the project's database image shows that, on PGroonga 4.0.5, a cleanup in one session deletes the Groonga objects of an index another session has built and not committed, and that on 4.1.0 it does not.
- R2. A negative control shows that dropping a throwaway schema that held PGroonga indexes breaks no later PGroonga lookup on its own.
- R3. The collision of two concurrent cleanups behind "failed to remove column ... failed to stat" is reproduced or bounded on both versions, including whether a later cleanup removes what a collision left.

**Repair**

- R4. Every database that the backend suite, CI and new instances create runs PGroonga 4.1.0 or later.
- R5. A test session that runs a PGroonga cleanup starts it with no leftover Groonga objects in the database, so its cleanup has nothing to race autovacuum over.
- R6. No PGroonga error is raised in a test session in any order: not in repeated full runs with different seeds, and not in CI step 41.
- R10. The persistent development instance runs PGroonga 4.1.0 with its data intact, and every PGroonga index in its database answers a search.

**Guards**

- R7. An integration test inside the suite fails when the database image's PGroonga deletes an uncommitted index's objects, and its failure leaves nothing that breaks a later test.
- R8. A guard fails when a test file adds a statement that runs a PGroonga cleanup without clearing leftover objects first or a reviewed exemption.

**Knowledge**

- R9. The extension inventory records the cleanup mechanism, the fixing version, the remaining collision, the test stacks' PostgreSQL settings and the evidence for each; the solution page, the roadmap and the developer guide state the corrected cause and the new version.

### Success Criteria

- Five full backend runs with distinct seeds after U5 show no `pgroonga:` ERROR raised by a test session in any lane's PostgreSQL log. PGroonga errors raised inside autovacuum (log context "automatic vacuum" or "automatic analyze"), if any, are counted and recorded.
- The first GitHub CI run that pulls the new image shows no PGroonga error from a test session in step 41, and its lane durations are recorded for the budget decision. A step 41 that runs out of its budget goes to the user with those durations, since the budget stays theirs.
- After the move, the persistent instance reports `extversion` 4.1.0 on its own database cluster (its `system_identifier` unchanged), every PGroonga index answers a search, and `make dev-status` shows it ready.

### Scope Boundaries

**Considered and not built**

- Autovacuum off in the test stacks. It would close both races in tests without an upgrade, but it makes the test databases differ from production, lets the catalogs bloat over about 500 schema replays per run, and fixes nothing outside tests. It remains the fallback only if P3 or P4 fails.
- A shared drop helper that clears leftover objects right after every `DROP SCHEMA`. Leftover objects are harmless unless two cleanups race over them, and KTD2 clears them where a test session runs a cleanup. A PGroonga error from a test session that runs no cleanup would change this.
- A guard on `DROP SCHEMA` of a schema holding PGroonga objects, as the brief proposed. Dropping is not the trigger (KTD4).
- `log_autovacuum_min_duration = 0` committed to `compose.test.yaml`. U1 uses it once for diagnosis.
- A migration running `ALTER EXTENSION pgroonga UPDATE`. The application calls nothing the 4.0.5 to 4.1.0 scripts add. The persistent instance runs the statement by hand in U7. A preview keeps its retained image; one whose image is lost restarts on a 4.1.0 build with the old extension version, and U6 documents the statement it then needs before its next migration.
- A change to the backend time budget (user decision) or to the runner's lanes: each stack lane already has its own PostgreSQL, and the failure happens inside one lane's database.
- A change to how throwaway schemas are built (KTD5).

**Deferred to Follow-Up Work**

- About 500 test methods in about 50 files rebuild a throwaway schema by replaying 28 migrations. A template database could make that cheaper. It is a duration question, not a correctness one, and needs per-test database connections.
- The test stacks start PostgreSQL with its default configuration, not `docker/postgres/conf/postgresql.conf`: `pgroonga.enable_wal` off and default memory and autovacuum settings. U6 records it in the inventory; aligning them is separate work.
- The stale version list in `.agents/skills/postgres-remediation/references/doctrine.md`, an existing roadmap housekeeping item.
- An upstream report of the concurrent-cleanup collision with P3's reproduction, if the user wants one.
- Nothing rebuilds the local `baander-database:latest` tag that the test runner selects by default (`packages/baander-tooling/resources/tooling-defaults.json`, `compose.test.yaml`), so local runs can test an image the Dockerfile no longer describes. U6 documents it; a check or rebuild in the runner is separate tooling work.

### Outstanding Questions

**For the user**

- OQ3. Push order. Default: when the user asks to push, push `master` up to U2's commit first, wait until "Build PostgreSQL Image" has published `baander-postgres:latest`, then push the rest. CI pulls `:latest` at the start of its run, and `postgres.yaml` rebuilds it only on a `master` push that touches `docker/postgres/**`, so a CI run carrying U4's test before then would test the old image.

**Deferred to implementation**

- How tests count leftover objects: `pgroonga_command('object_list')` filtered to `Sources` names and compared with the relfilenodes of PGroonga indexes in `pg_class`, or a narrower Groonga command if that output is too large.
- The names of the helper, the regression test and the guard.
- Where U7 keeps its backup and the saved image: a private directory outside every checkout, such as the instance's state directory beside the controller's own checkpoints.

### Sources / Research

- PGroonga source, tags 4.0.5 and 4.1.0, read 2026-11-16: `src/pgroonga.c` (`PGrnRemoveUnusedTables`, `PGrnRemoveUnusedTable`, `pgroonga_vacuumcleanup`, `PGrnEnsureLatestDB`), `src/pgrn-pg.c` (`PGrnPGIsValidFileNodeID` and, in 4.1.0, `PGrnPGIsCommittedOrUncommittedFileNodeID`), `src/pgrn-groonga.c` (`PGrnRemoveColumns`, `PGrnRemoveObjectForce`), `src/pgrn-vacuum.c` (`pgroonga_vacuum()`), `src/pgrn-writable.c`, `src/pgrn-list-broken-indexes.c`, and `data/pgroonga--4.0.5--4.0.6.sql` through `data/pgroonga--4.0.9--4.1.0.sql`. `PGrnRemoveUnusedTables`, `PGrnRemoveColumns` and `_PG_init` are identical in 4.0.5, 4.1.0 and on `main`.
- Upstream: issue GH-1013 and PR GH-1014 (merged 2026-10-09, commit `63870580ce91`), the 4.1.0 release notes (2026-10-09), the release notes of 4.0.6, 4.0.8 and 4.0.9, the upgrade compatibility table at `https://pgroonga.github.io/upgrade/`, and issue GH-252 (2022).
- Package index `https://packages.groonga.org/debian/dists/trixie/main/binary-amd64/Packages.gz`, read 2026-11-16: `postgresql-18-pgdg-pgroonga` 4.1.0-1, depending on `libgroonga0 (>= 16.1.3)`.
- PostgreSQL source, branch `REL_18_STABLE`, read 2026-11-16: `src/backend/postmaster/autovacuum.c` (`table_recheck_autovac` sets `VACOPT_SKIP_LOCKED` unless the run prevents wraparound), `src/backend/commands/vacuum.c` (`vacuum_open_relation` skips a relation whose lock is not available) and `src/backend/storage/lmgr/proc.c` (`ProcSleep` cancels a blocking autovacuum worker that is not preventing wraparound).
- PostgreSQL logs of the runs in the Problem Frame table; the CI log shows PostgreSQL 18.7 started with initdb's default configuration.
- `docs/solutions/developer-experience/pgroonga-indexes-in-throwaway-test-schemas-break-later-suites.md`: the first occurrence and the race fixtures' copy without PGroonga indexes; its cause is the one this plan corrects.
- `docs/solutions/best-practices/debian-13-base-images-change-collation-time-zone-links-and-vaapi-limits.md`: the last database image change and how an existing data volume was moved.
- `.agents/skills/postgres-remediation/references/extensions.md`: the PGroonga 4.0.5 inventory and its maintenance protocol.
- `tests/Unit/Shared/Infrastructure/Doctrine/PoolSafeSqlConventionTest.php` with `tests/Support/Doctrine/PoolSafeSqlScanner.php`: the reviewed-list source scan U5 follows.
- `tests/Fixtures/Catalog/ArtistImportIdentityRace.php` (`connect`): the two-connection setup U3 and U4 follow.
- Development instances: `packages/baander-tooling/src/Development/Build/Builder.php` (`prepare`: a persistent release builds from the instance's managed checkout of remote `master`; a retained data image is kept while the daemon holds it), `ReleaseContract.php` (the effective data image), `Updater.php` and `Runtime.php` (`down` runs `compose down --remove-orphans` and keeps volumes), `Database.php` (`checkpoint` dumps with `pg_dump --format=custom`, `rehearse` restores with `pg_restore --exit-on-error --single-transaction`), and the lost-image paragraph of `docs-book/part-2-developer-guide/getting-started/development-environment.md`.
- Image contents read on 2026-11-16 with `dpkg-query` inside each image: the persistent instance's database image, the stale `baander-database:latest` and the local copy of the CI image (Problem Frame, Observed in this repository).

---

## Planning Contract

### Mechanism

**Known from source and upstream**

- A PGroonga index keeps its data in Groonga objects named after the index's relfilenode (`Sources<relfilenode>`, `Lexicon<relfilenode>_<n>` and their columns), in one Groonga store per database (`base/<database oid>/pgrn*`), outside PostgreSQL's catalog and transactions.
- PGroonga has no drop hook. `DROP INDEX`, `DROP SCHEMA ... CASCADE`, `DROP TABLE` and a rolled-back `CREATE INDEX` leave the objects in place.
- `PGrnRemoveUnusedTables` is the only path that removes them. `pgroonga_vacuumcleanup` calls it, so it runs in `VACUUM`, in `ANALYZE` without `VACUUM` (PostgreSQL calls index cleanup with `analyze_only`), and in autovacuum and autoanalyze, whenever the table processed has a PGroonga index. `pgroonga_vacuum()` calls it directly. It scans every `Sources*` object in the database and removes the objects of each relfilenode that does not resolve to a relation. Only a prepared transaction or `pgroonga_set_writable(false)` skips it, and both act database-wide.
- In 4.0.5 the check is `RelidByRelfilenumber` under the session's catalog snapshot, so an index that another session built and has not committed counts as unused. 4.1.0 re-checks a miss against `pg_class` with a dirty snapshot, which sees uncommitted rows.
- GH-1013, reported on 4.0.6 and PostgreSQL 18.3, shows the effect: the index answers "object isn't found: <SourcesN>" until `REINDEX`. It reproduced with an autocommit `CREATE INDEX` in 2 to 9 percent of tries under an `ANALYZE` loop, and autovacuum off made it disappear. PGroonga 4.1.0 fixes it.
- Nothing serializes two cleanups. `PGrnRemoveColumns` raises "failed to remove column" when `grn_obj_remove` fails, which happens when another process has already removed the file. 4.1.0 leaves this unchanged; in GH-252 a maintainer suspected concurrent `VACUUM` and proposed exclusion, which never landed.
- PostgreSQL can keep autovacuum away from a table. `VACUUM` and `ANALYZE` take SHARE UPDATE EXCLUSIVE, which conflicts with itself. Every autovacuum run except an anti-wraparound one carries `VACOPT_SKIP_LOCKED` (`table_recheck_autovac` in `src/backend/postmaster/autovacuum.c`, PostgreSQL 18) and skips a table whose lock it cannot get at once. A session that holds that lock on every PGroonga-indexed table is therefore the only session that can run the cleanup through `VACUUM` or `ANALYZE`. When the helper has to wait for a lock that such a worker holds, `ProcSleep` (`src/backend/storage/lmgr/proc.c`) cancels the worker after `deadlock_timeout`. The worker may then be partway through PGroonga's cleanup, so the helper's own cleanup must finish whatever the worker left.
- 4.0.5 to 4.1.0 is a compatible upgrade on PGroonga's table. The extension scripts on the way add only `pgroonga_physical_table_names()` (4.0.9). The release notes change no tokenizer, normalizer or stemming behaviour the project's indexes use. 4.0.9 starts counting PGroonga index scans in `pg_stat_user_indexes`, and no test reads that for a PGroonga index.

**Observed in this repository**

- The failing transaction is `FinalSchemaBootstrap`'s replay (Problem Frame). Its window lasts as long as the replay, seconds on a CI runner. Migration tests such as `AlbumMatchClaimMigrationTest` open the same window with their own replay in one transaction.
- Inside a stack lane, test files run one at a time, each lane has its own PostgreSQL, and neither the failing tests nor `src/` run `ANALYZE` or `VACUUM`. Autovacuum is the only remaining session that can run the cleanup. That is an inference by elimination, since `log_autovacuum_min_duration` is at its default and the logs hold no autovacuum lines.
- The test stacks start the database image with PostgreSQL's default configuration: `compose.test.yaml` sets no command, and the CI log shows initdb's defaults. Autovacuum is on and `pgroonga.enable_wal` is off, unlike `docker/postgres/conf/postgresql.conf`.
- `PgroongaSearchCompatibilityTest` builds a PGroonga index in a throwaway schema and drops it, and has never failed: `SchemaTool::createSchema` (ORM 3.7.6) runs each statement in autocommit, so its window is one statement.
- The race fixtures under `tests/Fixtures/Catalog` copy tables with `ArtistImportIdentityRace::copyTableWithoutPgroonga` (`MatchApplyRace::copyTables` and `AlbumMergeRace::copyTables` included). `tests/Fixtures/Media/image-read-scope-plan.php`, a script run by hand, copies `albums`, `artists` and `songs` with their PGroonga indexes and runs `ANALYZE` on them.
- The test runner selects `baander-database:latest` unless `BAANDER_TEST_POSTGRES_IMAGE` names another image, and nothing in the repository rebuilds that local tag. The stale Debian 12 build described in the Problem Frame is what local runs have tested.
- The persistent instance's database runs its own build, `baander-development-postgres:4ef7de4a3...`, made from `docker/postgres/Dockerfile` at revision `4ef7de4a3` on 2026-11-09. It holds Debian 13.7, PostgreSQL 18.6, `libgroonga0` 16.1.5 and PGroonga 4.0.5-1, and it is not built from `baander-database:latest`. The image also carries the instance's release pins for the postgres and pooler roles.
- How the persistent instance takes a new database image. `Builder::prepare` builds a persistent release from the instance's managed checkout of remote `master`, never from an agent's checkout. It keeps the recorded data image while the daemon still holds that image ID, and builds the role's image from the release source only when the daemon answers "No such image". The development guide documents that path for images lost while an instance is stopped: the retained data is started on the newly built image. `make dev-down` runs `compose down --remove-orphans`, which removes the containers and keeps the volumes, so a stopped instance's data image is held by no container and can leave the daemon. A configured image reference instead of the default would be pulled from a registry, which carries 4.1.0 only after the same push.
- The only cleanup statements that test sessions run on PGroonga-indexed tables are `CoveredIndexPersistenceTest`'s `ANALYZE songs` on `public` and `image-read-scope-plan.php`'s `ANALYZE`. `UserEmailLookupTest` analyzes `users`, `RetagLockOrderTest` analyzes a copy of `songs` without its PGroonga index, and `tests/Fixtures/Session/playback-snapshot-cost.php` analyzes playback tables: none of them has a PGroonga index.

**Not used, as the brief asked**

- `pgroonga_command('object_remove ...')` removes by name what the cleanup removes, minus the alias and index-status records, and is still a cleanup that can collide.
- The `pgroonga_wal_*` functions apply or truncate the WAL of index changes and play no part in the cleanup's check; `pgroonga.enable_wal` is off in the test stacks.
- `VACUUM` is the cleanup itself. It helps only as the one cleanup running at a time.

**To establish by reproduction (U1):** P0 to P4 in the matrix below, on the 4.0.5 image and on a 4.1.0 build.

### Key Technical Decisions

- KTD1. **Fix the root cause by moving the database image to PGroonga 4.1.0-1.** The defect is PGroonga's check, and tests, previews and future installs share it: any migration that builds or rebuilds a PGroonga index while autovacuum runs can lose it. The upgrade is compatible and needs no migration, and the extension scripts add one function the application does not call. Existing databases keep working on the new library and update their extension SQL when they move; the persistent instance moves in U7 (KTD7). (session-settled: user-approved — chosen over keeping 4.0.5 with autovacuum off in the test stacks: the upgrade fixes the defect for tests, previews and installs alike)
- KTD2. **A test session clears leftover Groonga objects before it runs a cleanup, through one helper that first locks every PGroonga-indexed table against autovacuum; autovacuum stays on.** After KTD1 the only path to a test failure is two cleanups racing over the same leftover objects. The helper takes SHARE UPDATE EXCLUSIVE on every table with a PGroonga index, which autovacuum skips rather than waits for, and then runs `pgroonga_vacuum()` once as the only cleanup in the database. The locks last until the helper's transaction ends, or the caller's when it runs inside one. The session's own `ANALYZE` or `VACUUM` then finds nothing to remove. A worker that already holds a lock is cancelled before the helper gets it, and P4 checks that the helper's cleanup finishes what such a worker left. No collision can reach the helper, so it needs no retry, and no absorbed error lands in the server log. The test databases keep autovacuum, as production does.
- KTD3. **The regression test drives the race with two connections and an explicit cleanup, not with timing.** Session A holds an uncommitted `CREATE INDEX ... USING pgroonga`. Session B runs `ANALYZE` or `VACUUM` of a committed PGroonga-indexed table, or `pgroonga_vacuum()`. A then commits and uses the index. The order is fixed by the test, so it fails every time on 4.0.5.
- KTD4. **The guard scans test sources for cleanup statements against a reviewed list, and replaces the `DROP SCHEMA` guard the brief proposed.** Dropping a schema leaves objects that only matter when two cleanups meet; after KTD1 a fresh index is safe. A new cleanup statement in a test is the thing that can reintroduce a failure. The scan follows `PoolSafeSqlConventionTest`: a path-to-count list with a reason per entry.
- KTD5. **Throwaway schemas are built and dropped as today.** `FinalSchemaBootstrap` keeps its single transaction and its PGroonga indexes, migration replays keep their PGroonga statements, and the race fixtures keep copying tables without PGroonga indexes. After KTD1 the long transaction is safe, and leaving PGroonga out would change what the migration tests verify.
- KTD6. **The new image is published before the CI run that carries the regression test** (OQ3), and U4's version floor makes a stale image fail with a message that names 4.1.0.
- KTD7. **The persistent instance moves to the 4.1.0 image in this work, through the documented lost-image path, after a verified backup.** Its database keeps its volume, identity and controller state. With the instance stopped, the retained 4.0.5 image is saved to a file and removed from the daemon, so the next `make dev-up` builds the 4.1.0 image from remote `master` and starts the retained data on it. `ALTER EXTENSION pgroonga UPDATE`, the broken-index probe and a search through each PGroonga index follow over a direct owner connection, after the same steps have passed on a copy of the volume. The move waits for the push, because the instance builds only from remote `master`. (session-settled: user-directed — chosen over leaving the instance on 4.0.5 with the steps recorded for later: the instance holds the user's library, and on 4.0.5 any migration that builds a PGroonga index while autovacuum runs can break that index until `REINDEX`)

### Alternatives Considered

| Direction | Why not chosen |
|---|---|
| Drop PGroonga indexes before dropping the schema | PGroonga has no drop hook: dropping the index leaves the same objects, and the failure hits a fresh index before any drop. |
| Replay migrations without PGroonga indexes, or with plain indexes, in throwaway schemas | Avoids the window in tests only. Production keeps the defect, the migration tests stop covering migrations that touch indexed columns, and tests that rely on search in a throwaway schema would need an inventory and an opt-in. |
| A disposable database per test, or per lane, from a template | Per lane already holds. Per test, autovacuum still runs inside each database, and about 50 files would need per-test connection URLs, kernel tests included. Worth a plan of its own for duration (Deferred to Follow-Up Work). |
| Schema-replaying tests in a lane of their own, run last | The failing tests are the replaying tests themselves, about 50 files; moving them keeps autovacuum beside them. |
| Autovacuum off in the test stacks | Closes both races in tests without the upgrade, but changes the test databases, risks catalog bloat over a run, and fixes nothing outside tests. The fallback if P3 or P4 fails. |
| Build PGroonga indexes after the replay transaction, in autocommit | Shrinks the window to one statement; GH-1013 reproduces with an autocommit `CREATE INDEX`. |
| `pgroonga_set_writable(false)` around a test's `ANALYZE` | The flag lives in the database's Groonga store and blocks every session's PGroonga writes. |
| Move the persistent instance by restoring its dump into a fresh database | Gives up the instance's volume, recorded identity and controller state for a path the tooling does not document. The verified dump stays as the fallback for a move that fails. |
| Retry the cleanup on PGroonga's removal errors | A removal error aborts the caller's transaction, and `CoveredIndexPersistenceTest` runs inside one (`OwnershipPersistenceHarness::setUp`). Every absorbed collision would still leave an ERROR in the server log. Holding the locks of KTD2 prevents the collision instead. |

### High-Level Technical Design

The failure on 4.0.5, and what 4.1.0 changes:

```mermaid
sequenceDiagram
  participant T as Test session (FinalSchemaBootstrap)
  participant V as Autovacuum worker
  participant G as Groonga store of the database
  T->>T: BEGIN, replay 28 migrations
  T->>G: CREATE INDEX USING pgroonga creates Sources<N> and Lexicon<N>_0
  V->>V: VACUUM or ANALYZE of a table with a PGroonga index
  V->>G: scan Sources*; N has no visible pg_class row
  V->>G: remove Sources<N> and Lexicon<N>_0 (4.0.5)
  Note over V,G: 4.1.0 re-checks with a dirty snapshot, sees N and keeps it
  T->>T: COMMIT
  T->>G: INSERT INTO albums looks up Sources<N>
  G-->>T: object isn't found: <SourcesN>
```

The probes U1 runs, with the outcome each version should show:

| Probe | Sessions and steps | 4.0.5 | 4.1.0 |
|---|---|---|---|
| P0, negative control | A builds a schema with a PGroonga index in autocommit, writes, drops the schema. A fresh connection writes to and searches a PGroonga table in `public`, then runs `pgroonga_vacuum()` and repeats. Autovacuum off. | passes | passes |
| P1, two sessions | A: `BEGIN`, `CREATE TABLE`, `CREATE INDEX USING pgroonga` with the project's options. B: `ANALYZE` of a committed PGroonga table, then `VACUUM`, then `pgroonga_vacuum()`, one run each. A: `COMMIT`, insert, search with sequential scans off. Autovacuum off. | "object isn't found" every run | passes |
| P2, autovacuum alone | As P1 without B: autovacuum on with a one-second naptime, a PGroonga table in `public` with enough churn to be processed, A holding its transaction for ten seconds, `log_autovacuum_min_duration = 0`. | objects lost, log shows the worker | passes |
| P3, collision | Autovacuum off. 30 iterations; in each, 50 schemas with a PGroonga index are created and dropped, then two sessions wait on an advisory lock a third holds and both run `pgroonga_vacuum()` when it is released. Each iteration records any error, then runs a cleanup alone and counts leftover `Sources` objects. | some "failed to remove" errors; the cleanup run alone succeeds and leaves none | same |
| P4, exclusion | Autovacuum on with a one-second naptime, `log_autovacuum_min_duration = 0`. Case 1: a session takes SHARE UPDATE EXCLUSIVE on every PGroonga-indexed table and holds it while one of those tables gets enough churn to be processed. Case 2: with leftover objects from 50 dropped schemas present, the helper requests its locks while autovacuum is processing a PGroonga-indexed table; 20 iterations. | case 1: autovacuum skips the locked tables, whose autovacuum and autoanalyze counters stay put until the lock is released; case 2: the worker is cancelled, the helper's cleanup raises no error and leaves no `Sources` orphan | same |

### Assumptions

- Autovacuum is the concurrent cleanup in the lanes. P2 and U1's diagnostic run test this.
- No anti-wraparound autovacuum runs in a test database, since a fresh database stays far below `autovacuum_freeze_max_age` during a run. Such a run would not skip a locked table, but it would wait for the lock rather than clean beside the helper.
- `postgresql-18-pgdg-pgroonga=4.1.0-1` stays installable from the Groonga repository for trixie, as on 2026-11-16. The exact pin carries the same risk 4.0.5-1 carries today.
- Search behaviour on 4.1.0 matches 4.0.5 for the project's index options. The release notes say so, and `PgroongaSearchCompatibilityTest` and `CatalogReadScopeRepositoryTest` check it on the new image.
- With a base rate of five failures in 243 retained local runs and two of two CI runs, five clean full runs are supporting evidence, not proof. P1 and U4 are the proof.
- The 4.1.0 image builds on Debian 13 like the persistent instance's current image, so the move changes no glibc and no collation. U7 still checks for a collation version warning, as the Debian 13 move required.

### Sequencing

U1 runs first, on the current image, so the failure is recorded before the repair. U2 changes the pin. U3's helper needs U2 for its uncommitted-index scenario, and U4 uses the helper. U5 needs U3's helper to exist. `ce-simplify-code` and the five full runs follow U5. U6 records the results before the push. U7 runs once `master` holds U2 on the remote (OQ3), and a roadmap commit after it records the move and the first CI result.

### System-Wide Impact

- Test stacks, the messaging and worker runners, and new development instances build or pull the database image and get 4.1.0. CI gets it through `ghcr.io/.../baander-postgres:latest` once `postgres.yaml` publishes it.
- Development instances keep the PostgreSQL image their data runs on and take the pooler from each release; the pooler does not load PGroonga. The persistent instance's next automatic update after the push builds its pooler from the 4.1.0 Dockerfile and keeps its data on 4.0.5 until U7 moves it. Previews keep their own retained images and are not moved.
- U7 stops the persistent instance for the length of the move, and its automatic updates stay off from `make dev-down` until `make dev-up`.
- No application code, migration, API or worker behaviour changes.

### Risks

| Risk | Mitigation |
|---|---|
| The first CI run after a push pulls the old `:latest` image | OQ3's push order; U4's version floor names the cause when it happens. |
| 4.1.0's dirty-snapshot check scans `pg_class` for each leftover relfilenode, so cleanups cost more while dropped schemas fill `pg_class` | Lane durations of the five full runs are compared with the third attempt of run 43695574134 and recorded. |
| Two autovacuum workers collide and leave objects that a later cleanup, run alone, cannot remove; the helper would then fail | P3 checks convergence; a stop condition sends the plan to the autovacuum-off fallback. |
| Removing the retained 4.0.5 image from the daemon leaves no way back to it | U7 saves the image to a file first. Loading it restores the same image ID, a way back only until `make dev-up` activates the new release: from then on the instance's state names the 4.1.0 image. U7's rehearsal on a volume copy tests the in-place path before the image goes. |
| `make dev-up` refuses to start the retained data on the new image, or asks for a repair or data reset | Stop condition. The instance stays stopped on a volume PostgreSQL may already have run on 4.1.0, and the user chooses between repairing forward on 4.1.0 and restoring the verified dump. |
| An index built under 4.0.5 is already broken in the persistent database | The broken-index probe raises on it; U7 rebuilds it with `REINDEX` and searches through it again. |
| The first CI run after the push runs out of the 3,600-second budget for runner reasons, as the run on `8d119f5df` did | R6's CI half is judged from the logs of the lanes that finished: no PGroonga error from a test session. The lane durations go to the user for the budget decision, which stays theirs. |
| `pgroonga_command('object_list')` output grows large in a lane that has dropped hundreds of indexes | Counting leftover objects is a test assertion only; a narrower command is an implementation choice. |

---

## Implementation Units

### U1. Reproduce the cleanup race on disposable containers

- **Goal:** establish R1 to R3, and the lock exclusion KTD2 relies on, on the project's image before changing it.
- **Requirements:** R1, R2, R3.
- **Dependencies:** none.
- **Files:** none committed. The results go to the documents of U6.
- **Approach:**
  1. Build both images from today's `docker/postgres/Dockerfile` on the same day, one with its default `PGROONGA_VERSION=4.0.5-1` as `baander-database:pgroonga-4.0.5` and one with `PGROONGA_VERSION=4.1.0-1` as `baander-database:pgroonga-4.1.0`. The before image is this fresh build, not the stale `baander-database:latest` and not the CI image, so the two differ in the PGroonga pin alone. Record for each the Debian release and the `postgresql-18`, `libgroonga0` and `postgresql-18-pgdg-pgroonga` package versions (`dpkg-query`), and `extversion` in a fresh database.
  2. Run P0, P1 and P2 from the matrix on disposable containers of each image, with `CREATE EXTENSION pgroonga` in a fresh database.
  3. Run P3 on each image and record every error with its SQLSTATE and message, how long the colliding cleanups took, and the leftover count after the third cleanup.
  4. Run P4 on the 4.1.0 build, the image KTD2's helper will run on.
  5. Run one full backend suite on the 4.0.5 image with `log_autovacuum_min_duration = 0`, set through a local, uncommitted change to `compose.test.yaml`, and check that autovacuum processed PGroonga-indexed tables during the lanes. The run need not fail.
- **Execution note:** disposable containers and test stacks only; the postgres specialist runs it.
- **Test expectation:** none -- evidence gathering; the in-suite proof is U4.
- **Verification:** each probe has a recorded outcome for each version that matches the matrix, or the plan stops as the Goal Capsule says.

### U2. PGroonga 4.1.0 in the database image

- **Goal:** every build of the database image installs PGroonga 4.1.0-1 (KTD1).
- **Requirements:** R4.
- **Dependencies:** U1.
- **Files:**
  - modify `docker/postgres/Dockerfile` (`ARG PGROONGA_VERSION`) and `docker-compose.yml` (the `PGROONGA_VERSION` build argument);
  - modify `docs-book/part-2-developer-guide/development/database-naming.md` and `docs-book/part-2-developer-guide/development/search.md` (the package version they name).
- **Approach:** change the pin in both places and leave the stem filter and MeCab packages unpinned as today. `scripts/tests/php/PresetPostgresSettingsTest.php` keeps its `'pgroonga' => '4.0.5'` snapshot fixture, since the settings check only requires the extension to be present.
- **Patterns to follow:** the Debian 13 image move in `docs/solutions/best-practices/debian-13-base-images-change-collation-time-zone-links-and-vaapi-limits.md`.
- **Test expectation:** none -- an image pin; U4 proves the behaviour and the version floor.
- **Verification:** the image builds and resolves `libgroonga0` 16.1.3 or later; `baander-database:latest` is rebuilt from the changed Dockerfile, which replaces the stale Debian 12 build on this host, and its package versions are recorded; a stack lane's database reports `extversion` 4.1.0; `PgroongaSearchCompatibilityTest`, `CatalogReadScopeRepositoryTest` and the tooling tests that read `docker/postgres` (`DevelopmentImagePinsTest`, `PresetDeploymentTest`, `PresetPostgresSettingsTest`, `PythonBoundaryTest`) pass.

### U3. Clear leftover objects before a test session's cleanup

- **Goal:** a test session that runs a PGroonga cleanup finds no leftover objects to race autovacuum over (KTD2).
- **Requirements:** R5.
- **Dependencies:** U1 (P4), U2.
- **Files:**
  - create the helper under `tests/Support/Doctrine/`;
  - modify `tests/Integration/CoveredIndexPersistenceTest.php` (clear before its `ANALYZE` loop, which includes `songs`) and `tests/Fixtures/Media/image-read-scope-plan.php` (clear before its `ANALYZE`);
  - create an integration test of the helper in `tests/Integration/`.
- **Approach:** in one transaction, the helper reads every table that has a PGroonga index (`pg_index` joined to `pg_class` and `pg_am` where `amname = 'pgroonga'`), locks them in OID order in SHARE UPDATE EXCLUSIVE mode, and runs `SELECT pgroonga_vacuum()` once. Called in autocommit it opens and commits its own transaction. Called inside the caller's transaction, as `CoveredIndexPersistenceTest` does through `OwnershipPersistenceHarness`, it uses a nested transaction, so the locks last until the caller's transaction ends and cover the caller's `ANALYZE`.
- **Patterns to follow:** `ArtistImportIdentityRace::connect` for a second connection; DBAL's nested `transactional()` for the savepoint.
- **Test scenarios:**
  - After a throwaway schema with a PGroonga index is created and dropped, the helper leaves no `Sources` object whose relfilenode is missing from `pg_class`.
  - While a second session holds an uncommitted `CREATE INDEX ... USING pgroonga` on a table it created in the same transaction, the helper keeps that index's objects, and after the commit an insert and a search through the index succeed. The table must be new: an uncommitted index build on an existing table holds a SHARE lock, and the helper's lock would wait for it.
  - Called inside an open transaction, the helper returns with the locks held: a second session's `LOCK TABLE songs IN SHARE UPDATE EXCLUSIVE MODE NOWAIT` fails with SQLSTATE 55P03, and succeeds once the first transaction ends.
  - Called in autocommit, the helper leaves no lock behind: the same `NOWAIT` lock succeeds right after it returns.
- **Verification:** the scenarios pass on the 4.1.0 image, and `CoveredIndexPersistenceTest` passes.

### U4. Regression test for an index built before its commit

- **Goal:** the suite fails whenever the database image's PGroonga deletes an uncommitted index's objects (KTD3, KTD6).
- **Requirements:** R7, R4.
- **Dependencies:** U2, U3.
- **Files:**
  - create the regression test in `tests/Integration/`;
  - modify `tests/Integration/PgroongaSearchCompatibilityTest.php` (raise its `extversion` floor from 2.0.4 to 4.1.0, with a message that names the cleanup fix).
- **Approach:** two connections on `OUTBOX_TEST_DATABASE_URL` and a throwaway schema with a committed PGroonga-indexed table. Session B clears leftover objects through U3's helper before session A begins its transaction: cleared any later, the helper would itself remove A's objects on 4.0.5. B's own cleanup then finds nothing to collide with autovacuum over. Teardown drops the schema as other tests do.
- **Execution note:** write the test first and run it on the 4.0.5 image. The three race cases must each fail with "object isn't found", and the version-floor case with the 4.1.0 message, before all of them pass on 4.1.0. On 4.0.5, run `PgroongaSearchCompatibilityTest` and `CoveredIndexPersistenceTest` after it in the same lane to show the failure leaves nothing that breaks them.
- **Test scenarios:**
  - Covers R7. Session B runs `ANALYZE` of the committed table while session A holds an uncommitted `CREATE INDEX` on a new table; after A commits, an insert into A's table and a `&@~` search with sequential scans off return the row.
  - The same with `VACUUM` of the committed table in session B.
  - The same with `SELECT pgroonga_vacuum()` in session B.
  - `PgroongaSearchCompatibilityTest` fails with the 4.1.0 message when `extversion` is lower.
- **Verification:** all cases fail on the 4.0.5 image and pass on 4.1.0. On 4.0.5, `CoveredIndexPersistenceTest` and every `PgroongaSearchCompatibilityTest` case except the version floor pass after them.

### U5. Guard on cleanup statements in tests

- **Goal:** a new test cannot run a PGroonga cleanup unreviewed (KTD4).
- **Requirements:** R8.
- **Dependencies:** U3.
- **Files:**
  - create a scanner in `tests/Support/Doctrine/` and the guard in `tests/Unit/Shared/Infrastructure/Doctrine/`.
- **Approach:** scan the PHP files under `tests/` for SQL that runs a cleanup: `ANALYZE` other than `EXPLAIN (ANALYZE ...)` or `EXPLAIN ANALYZE`, `VACUUM`, and `pgroonga_vacuum(`. A file passes when it calls U3's helper or appears in a reviewed path-to-count list with a reason. The list starts with `tests/Functional/Auth/UserEmailLookupTest.php` (`users`), `tests/Integration/RetagLockOrderTest.php` (a copy of `songs` without its PGroonga index) and `tests/Fixtures/Session/playback-snapshot-cost.php` (playback tables). The failure message points to the helper and the list.
- **Patterns to follow:** `PoolSafeSqlConventionTest` and `PoolSafeSqlScanner`.
- **Test scenarios:**
  - The current tree passes.
  - `ANALYZE songs`, a lowercase `analyze albums`, `VACUUM ANALYZE artists`, `SELECT pgroonga_vacuum()` and `'ANALYZE ' . $table` are each flagged in a file that does not call the helper.
  - `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) SELECT ...` and `EXPLAIN ANALYZE SELECT ...` are not flagged.
  - A file that calls the helper passes with a cleanup statement in it.
  - A listed file with one more occurrence than its count fails.
- **Verification:** the guard passes on the tree and each fixture behaves as listed.

### U6. Inventory, solution page, vocabulary and roadmap

- **Goal:** the shared knowledge states the corrected cause, the fix and the evidence (R9).
- **Requirements:** R9.
- **Dependencies:** U1 to U5 and the five full runs; U7 for the post-push roadmap commit.
- **Files:**
  - modify `.agents/skills/postgres-remediation/references/extensions.md` (postgres specialist): the image's PGroonga version; a short entry on the cleanup with the facts of the Mechanism section, GH-1013 and its fix in 4.1.0, the collision left in 4.1.0 and on `main` with P3's result, the probe `pgroonga_list_broken_indexes()`, the test stacks' default configuration, and the stale default test image: the runner selects the local tag `baander-database:latest`, which nothing rebuilds, and on this host it was a Debian 12 build from 2026-05-21; the memory figures marked as measured on 4.0.5 with Groonga 16.0.x and not re-measured on 4.1.0, which needs Groonga 16.1.3 or later; the claim that preloading does not work kept, since `_PG_init` is unchanged in 4.1.0;
  - rename `docs/solutions/developer-experience/pgroonga-indexes-in-throwaway-test-schemas-break-later-suites.md` to a name that states the cause, rewrite its cause, dates, solution and prevention, and update its one reference in `docs/plans/2026-11-15-1624-fix-matching-leftovers-plan.md`;
  - modify `docs-book/part-2-developer-guide/testing/conventions.md`: a test that runs `ANALYZE` or `VACUUM` on a table with a PGroonga index clears leftover objects through the helper first;
  - modify `docs-book/part-2-developer-guide/getting-started/development-environment.md`, at the paragraph on images lost while an instance is stopped: retained data that starts on a newer database image keeps its old extension versions, and a pending migration's checkpoint rehearsal, which compares extension versions, blocks until `ALTER EXTENSION pgroonga UPDATE` has run over a direct owner connection;
  - modify `docs-book/part-2-developer-guide/testing/backend-runners.md`: the default PostgreSQL image is a local tag that only a manual build refreshes; rebuild it from `docker/postgres/Dockerfile` after the Dockerfile changes, or name a current build with `BAANDER_TEST_POSTGRES_IMAGE`;
  - modify `CONCEPTS.md` if "Throwaway schema" needs more than its current entry;
  - modify `ROADMAP.md`: the delivered units with their verification checkpoints, each probe's result, the lane durations, the corrected attributions under "Master CI repair" and "Matching follow-ups", the PGroonga part of the housekeeping item as done while its `doctrine.md` and `composer normalize` parts stay open, and, in the commit after the push, the persistent instance's move as done with U7's before and after versions, together with the first CI result.
- **Approach:** state behaviour in plain words without plan IDs, follow the inventory's maintenance protocol, and run `prose-fix` on every changed page.
- **Test expectation:** none -- documentation.
- **Verification:** `make website-build` succeeds, and the pages read as the built behaviour.

### U7. Move the persistent instance to the 4.1.0 image

- **Goal:** the persistent development instance runs PGroonga 4.1.0 on its existing data, and every PGroonga index answers a search (KTD7).
- **Requirements:** R10.
- **Dependencies:** U2 verified, and remote `master` holding U2's commit (OQ3). It runs after the push, because `Builder::prepare` builds the persistent instance's release, its database image included, from the instance's managed checkout of remote `master`. Before that push, an image rebuilt for the instance would still be 4.0.5.
- **Files:** none in the repository. The backup, the saved image and the record of versions go to a private directory outside every checkout.
- **Approach:**
  1. Record the before state: `make dev-status`, the database image's ID and its package versions (`dpkg-query` in the image), `extversion`, the PostgreSQL and Groonga versions, and the cluster's `system_identifier` from `pg_control_system()`.
  2. Take the backup while the instance runs, over a direct owner connection to its postgres service container rather than through the pooler. In one REPEATABLE READ transaction, export a snapshot and count the rows of `users`, `libraries`, `artists`, `albums`, `songs` and `playlists`. Then run `pg_dump --format=custom --snapshot=<that snapshot>` and copy the dump out of the container.
  3. Verify the backup before changing anything. Restore it with `pg_restore --exit-on-error --single-transaction` into a scratch container of the instance's current database image, compare the six counts with the snapshot's, and remove the scratch container.
  4. Save the retained database image to a file with `docker save`.
  5. Check that `make dev-status` shows the instance ready, with no failure recorded and its running revision equal to the commit `git ls-remote origin refs/heads/master` reports. A blocked instance would refuse the later `make dev-up`. An instance behind `master` would meet a pending migration at activation, whose checkpoint rehearsal compares extension versions and would block on the 4.1.0 restore.
  6. Run `make dev-down` from the main checkout on `master`. It stops the instance and its automatic updates, removes the containers and keeps the volumes.
  7. Rehearse the in-place path on a copy. Copy the stopped instance's database volume into a scratch volume, and start `baander-database:pgroonga-4.1.0` on the copy with the instance's PGDATA path. Run `ALTER EXTENSION pgroonga UPDATE`, the broken-index probe and, with sequential scans off, a `&@~` search through each PGroonga index. Then remove the scratch container and volume.
  8. Check again that remote `master` still names the instance's running revision, then remove the retained database image from the daemon. It is tagged once per release pin, so every tag goes with it. Check that `docker image inspect` on the recorded ID answers "No such image".
  9. Run `make dev-up` from the same checkout. The persistent checkout is the controller's managed clone; neither this command nor any agent branch moves it. Startup builds the database image from remote `master` and starts the retained data on it.
  10. Over a direct owner connection, run `ALTER EXTENSION pgroonga UPDATE`, then `SELECT * FROM pgroonga_list_broken_indexes()`. Run `REINDEX INDEX` on any index the probe names, and on any index whose lookup raises "object isn't found", which the probe itself raises on such an index.
  11. Verify: `extversion` is 4.1.0; `system_identifier` is the one recorded in step 1, so the instance runs on its own cluster; with sequential scans off, a `&@~` search through each PGroonga index returns without error; the server log shows no collation version warning; `make dev-status` shows the instance ready. Record the six counts beside the backup's, without comparing them, since the instance has served writes and merges since.
  12. Record the after state as in step 1. Keep the backup and the saved image until the roadmap entry is written.
- **Execution note:** this unit touches the user's library. Run it with `INSTANCE=persistent` only where a command needs the instance selection. A stop before step 9 leaves the instance able to restart on its 4.0.5 image: load the saved image if step 8 already removed it, then run `make dev-up`. From step 9 on, `Updater::activate` records the 4.1.0 image in the instance's state before any refusal can come, so no lifecycle command returns it to 4.0.5. A stop then leaves the instance stopped on a volume PostgreSQL may already have run on 4.1.0, and the user chooses between repairing forward on 4.1.0 and restoring the verified dump.
- **Patterns to follow:** `Database::checkpoint` and `Database::rehearse` in `packages/baander-tooling/src/Development/Database.php` for the dump and the restore; the owner connection of the Debian 13 move in `docs/solutions/best-practices/debian-13-base-images-change-collation-time-zone-links-and-vaapi-limits.md`.
- **Test expectation:** none -- an operation on the persistent instance; its checks are the Verification line.
- **Verification:** before the move, the backup restored with matching counts and the rehearsal on the volume copy passed. After it, the instance reports `extversion` 4.1.0 on the same `system_identifier`, every PGroonga index answers a search, and the before and after versions are recorded.

---

## Verification Contract

| Gate | Command | Applies to |
|---|---|---|
| Probes | Disposable containers of `baander-database:pgroonga-4.0.5` and `baander-database:pgroonga-4.1.0`, both built from today's Dockerfile, as U1 lists | U1 |
| Database image | `docker build --file docker/postgres/Dockerfile --tag baander-database:latest docker/` after the pin, which replaces the stale local Debian 12 build | U2 |
| Targeted files on both images | `BAANDER_TEST_IMAGE=<ci image> BAANDER_TEST_POSTGRES_IMAGE=baander-database:pgroonga-4.0.5` or `...:pgroonga-4.1.0 bash scripts/test-functional-container.sh <files>` with U3's and U4's tests, `PgroongaSearchCompatibilityTest`, `CoveredIndexPersistenceTest` and `CatalogReadScopeRepositoryTest`; the image is always named explicitly | U2, U3, U4 |
| Unit tests | `vendor/bin/phpunit -c phpunit.xml.dist <path>` on the host | U5 |
| Tooling tests reading `docker/postgres` | `vendor/bin/phpunit -c phpunit-tooling.xml.dist` on `DevelopmentImagePinsTest`, `PresetDeploymentTest`, `PresetPostgresSettingsTest` and `PythonBoundaryTest` | U2 |
| Full backend suite | `BAANDER_TEST_IMAGE=<ci image> BAANDER_TEST_POSTGRES_IMAGE=baander-database:pgroonga-4.1.0 bash scripts/test-backend-container.sh --random-order-seed <seed>`, five distinct seeds, each lane's compose log scanned for `pgroonga:` ERROR lines and each line classified by its context | after U5 |
| Static gates | `make phpstan`, then `php scripts/check-backend-container.php --image <ci image> -- vendor/bin/deptrac analyse --no-cache --no-progress` | after U5 |
| Documentation | `make website-build`, `prose-fix` on changed pages | U6 |
| Persistent instance | `make dev-status`, `make dev-down` and `make dev-up` from the main checkout on `master`; owner `psql` in the instance's postgres service container for the backup, the extension update, the probe and the searches; a scratch container for the backup's restore, and a scratch volume and container for the rehearsal on the volume copy | U7, after push |
| CI | After the user pushes in OQ3's order: "Build PostgreSQL Image" finished before the CI run pulled the image, step 41's logs hold no `pgroonga:` error from a test session, and its lane durations are recorded; a step 41 that runs out of its budget goes to the user with them | after push |
| Simplification and review | `ce-simplify-code` after U5; `ce-code-review` on the branch | before shipping |

The CI image is `baander-ci-prewarm:local`, built as `docs/solutions/developer-experience/local-gate-images-and-webkit-harness-runs.md` describes. A full run takes about 33 minutes on the development host, and each run uses its own disposable stacks.

---

## Definition of Done

- P0 to P4 have recorded outcomes that match the matrix.
- R1 to R5, R7 and R8 are met by passing tests or recorded probes named in units. R6 is met by the five full runs of the Verification Contract and, once the user has pushed, by CI step 41. R9 is met by U6's documentation check, and R10 by U7's Verification line.
- The persistent instance runs on the 4.1.0 image with its data, its before and after versions are in `ROADMAP.md`, and its verified backup and saved image are kept until that entry is written.
- Every gate in the Verification Contract passes, the five full runs show no PGroonga error from a test session, and justified `ce-code-review` findings are applied.
- The inventory, the renamed solution page, `conventions.md`, `CONCEPTS.md` and `ROADMAP.md` match what was built and measured.
- No code from abandoned attempts remains: no autovacuum setting in `compose.test.yaml`, no probe scripts, and no retry around PGroonga's cleanup.
- Per unit: its Verification line holds.
