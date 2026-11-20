# Extension-aware persistence and search

Read alongside [Doctrine](doctrine.md) when reviewing search SQL, custom column
types, index drift or a PostgreSQL runner. Paths are relative to this reference.

## Establish installed capabilities

[docker/postgres/Dockerfile](../../../../docker/postgres/Dockerfile) and
[docker-compose.yml](../../../../docker-compose.yml) build PostgreSQL 18 with
PGroonga package 4.1.0-1, Groonga stem filter and MeCab tokenizer packages, plus
PostgreSQL contrib. These are build intentions, not proof of the running image,
database extension version or enabled tokenizer. Do not substitute a stock
PostgreSQL container for extension-dependent migration/search verification.

A build of that Dockerfile on 2026-11-16 (image `181e00534c51`) held Debian 13.7,
`postgresql-18` 18.7-1.pgdg13+2, `postgresql-18-pgdg-pgroonga` 4.1.0-1, and
`libgroonga0`, `groonga-token-filter-stem` and `groonga-tokenizer-mecab` 16.1.5-1.
`pgroonga_command('status')` reported Groonga 16.1.5 and Apache Arrow 26.0.0, and a
fresh database created `pgroonga` at `extversion` 4.1.0. The package depends on
`libgroonga0 (>= 16.1.3)` (Debian trixie package index, read 2026-11-16). Use 4.1.0 or
later: before it, the [cleanup](#clean-up-leftover-groonga-objects) can delete the data
of an index whose transaction has not committed. 4.0.5 to 4.1.0 is a compatible upgrade
on [PGroonga's upgrade table](https://pgroonga.github.io/upgrade/); its extension
scripts add only `pgroonga_physical_table_names()` (4.0.9), which the application
does not call, so no migration runs the update. A retained database therefore keeps
the `extversion` it recorded until its owner runs `ALTER EXTENSION pgroonga UPDATE`.
Fresh disposable databases on the new image establish nothing about a retained
database. On 2026-11-16 the persistent development instance moved from its 4.0.5-1
build to a 4.1.0 build of the Dockerfile (image `971daf64233b`: Debian 13.7,
`postgresql-18` 18.7-1.pgdg13+2, `libgroonga0` 16.1.5-1, PGroonga 4.1.0-1) with its
data volume. `ALTER EXTENSION pgroonga UPDATE` ran over a direct owner connection and
`extversion` reads 4.1.0. The cluster's `system_identifier` is unchanged,
`pgroonga_list_broken_indexes()` returned no rows, and each of the five PGroonga
indexes answered a `&@~` search through the index.

[Version20261017000000](../../../../migrations/Version20261017000000.php)
creates `citext`, `ltree`, `pg_stat_statements`, `pg_trgm`, `pgcrypto`, `pgroonga`
and `uuid-ossp`. Installation in the image, availability to CREATE EXTENSION,
and installation in the selected database are distinct. Creation alone does
not establish application use: do not infer ltree/vector/spatial features from
a package list. No blanket removal of apparently unused extensions; check
column types, functions, defaults, operator/index dependencies and deployment consumers.

For an authorized database, run read-only catalog checks using the existing
connection environment without logging credentials:

```sql
SELECT version(), current_database(), current_schema();
SHOW search_path;
SELECT e.extname, e.extversion, n.nspname
FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace
ORDER BY e.extname;
SELECT name, default_version, installed_version
FROM pg_available_extensions
WHERE name IN ('pgroonga','pg_trgm','citext','ltree','pg_stat_statements','pgcrypto','uuid-ossp');
SELECT schemaname, tablename, indexname, indexdef
FROM pg_indexes WHERE schemaname NOT IN ('pg_catalog','information_schema')
ORDER BY schemaname, tablename, indexname;
SELECT n.nspname, c.relname, am.amname, i.indisvalid, i.indisready,
       pg_get_indexdef(i.indexrelid), c.reloptions
FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN pg_am am ON am.oid = c.relam
WHERE n.nspname NOT IN ('pg_catalog','information_schema');
SELECT n.nspname, opc.opcname, am.amname, opc.opcdefault,
       format_type(opc.opcintype, NULL) AS input_type
FROM pg_opclass opc JOIN pg_am am ON am.oid = opc.opcmethod
JOIN pg_namespace n ON n.oid = opc.opcnamespace
WHERE am.amname = 'pgroonga' OR opc.opcname = 'gin_trgm_ops';
```

Check extension schema and search_path when resolving operators/types. Verify
actual `pg_attribute` column types and each index's ordered `indclass` operator
classes for a proposed change; access method alone is insufficient. Compare
running extension versions with their matching upstream documentation before
using new options. `pg_stat_statements` also needs effective preload/configuration;
CREATE EXTENSION alone does not prove usable query statistics.

## Test databases

The disposable test stacks in [compose.test.yaml](../../../../compose.test.yaml)
give the postgres service no command, and the image's own command is plain
`postgres`. PostgreSQL there runs on initdb's default configuration, not on
[postgresql.conf](../../../../docker/postgres/conf/postgresql.conf) or the deployment
settings in [PostgresConfiguration](../../../../packages/baander-deployment/src/Database/PostgresConfiguration.php):
autovacuum is on with default settings, `pgroonga.enable_wal` is off, and neither
file's `shared_preload_libraries` line applies. The CI log and disposable containers
of both 2026-11-16 builds show these defaults. Aligning the test configuration with
the project files is separate work that has not been done.

Without `BAANDER_TEST_POSTGRES_IMAGE`, the runners select the local tag
`baander-database:latest` ([compose.test.yaml](../../../../compose.test.yaml),
[tooling-defaults.json](../../../../packages/baander-configuration/resources/tooling-defaults.json)),
and nothing in the repository rebuilds it. On the development host it was a Debian 12
build from 2026-05-21 (PostgreSQL 18.4-1.pgdg12+1, `libgroonga0` 16.0.4-1, PGroonga
4.0.5-1) until it was rebuilt from the 4.1.0 Dockerfile on 2026-11-16 (image
`f2bf72f71a76`). The dated observations in this file that name
`baander-database:latest` with PostgreSQL 18.4 match that stale build. CI pulls `ghcr.io/baander-app/baander-postgres:latest`, which
[postgres.yaml](../../../../.github/workflows/postgres.yaml) publishes on a `master`
push that changes `docker/postgres/`; a copy pulled on 2026-11-05 held PostgreSQL
18.6-1.pgdg13+2, `libgroonga0` 16.1.5-1 and PGroonga 4.0.5-1.

## Size memory per PGroonga backend

PGroonga is not preloaded. Each backend loads it, with libgroonga, Apache Arrow,
ggml/llama and MeCab, on first use of a PGroonga index, operator or function,
including writes to an indexed table. Measured 2026-11-03 on disposable
`baander-database:latest` (PostgreSQL 18.4, PGroonga 4.0.5, Groonga 16.0.4,
Arrow 24.0.0) and a rebuild (PostgreSQL 18.6, Groonga 16.0.5): a fresh backend
holds about 3 MiB RssAnon. Loading PGroonga raises it to 21 MiB, and a search
plus an UPDATE of the indexed column to 22 MiB. The memory stays until the
backend exits. Most of it is libgroonga's `.data.rel.ro` (9.3 MiB, dirtied by
relocation) and a heap of about 7 MiB from library initialisation, so
`pg_log_backend_memory_contexts` does not show it. `pgroonga.enable_wal`,
`GRN_GGML_BACKENDS_DIR`, `GRN_MRUBY_ENABLED` and glibc malloc trim tunables
changed it by at most 0.5 MiB. Budget about 24 MiB of private memory for every
connection that may use PGroonga, on top of shared_buffers. These figures come from
PGroonga 4.0.5 with Groonga 16.0.x and were not re-measured on 4.1.0, which requires
Groonga 16.1.3 or later.

When the host's THP mode is `always`, the sparsely touched large anonymous
mappings of these libraries can be backed by 2 MiB huge pages at fault time or
collapsed by khugepaged later. One backend reached 95 MiB RssAnon, 72 MiB of it
`AnonHugePages`. Whether this happens depends on how many free huge pages the
host has, so the same workload succeeds on one run and is OOM-killed on the next.
The image therefore starts its entrypoint through
[thp-disabled-exec](../../../../docker/postgres/thp-disabled-exec.c)
(`PR_SET_THP_DISABLE`, inherited by every backend). Check `THP_enabled: 0` in
`/proc/1/status` of the container and zero `AnonHugePages` in a backend's
`/proc/<pid>/smaps_rollup`. An image built before this change lacks the helper.

Do not put `pgroonga` in `shared_preload_libraries` to share this memory. In
4.0.5, and unchanged in 4.1.0, `_PG_init` is the only backend path that opens the
Groonga database, and it skips the open when no database is selected, as in the
postmaster. The upstream [GH-336 release note](https://pgroonga.github.io/news/) says preloading
does not work, for example `CREATE INDEX USING pgroonga` fails.
[`pgroonga.enable_wal`](https://pgroonga.github.io/reference/parameters/enable-wal.html)
defaults to `off` and is documented for streaming replication, with
`pgroonga_wal_resource_manager` recommended on PostgreSQL 15 and later. Both project
configurations enable it; the test databases run with it off. The page does not say
what turning it off means for crash recovery of a primary without replicas, so keep
it until that is verified.

## Preserve the actual search contract

Album, Artist, Song, Genre and Movie entity attributes and initial migration
declare PGroonga title/name indexes with `TokenNgram`, `NormalizerAuto`, and
`TokenFilterStem` loaded through `plugins='token_filters/stem'`. The image also
includes MeCab, but these index declarations do not select it. Match the actual
column type, operator class, query operator and index options before prescribing
a new tokenizer. Defaults are not equivalent to the repository's explicit settings.
[PGroonga CREATE INDEX documentation](https://pgroonga.github.io/reference/create-index-using-pgroonga.html)
describes these options and distinct text/varchar full-text operator classes.

[PgroongaMatch](../../../../src/Shared/Infrastructure/Doctrine/DQL/PgroongaMatch.php)
emits `&@~`. [PgroongaSearchTrait](../../../../src/Shared/Infrastructure/Doctrine/Repository/PgroongaSearchTrait.php)
has both DQL filtering and parameterized native scored SQL using
`pgroonga_score(tableoid, ctid)`. Both pass the query expression unchanged;
prefix matching requires an explicit suffix `*`, such as `Jaz*`, following the
[PGroonga v2 query operator contract](https://pgroonga.github.io/reference/operators/query-v2.html).
Appending `*` automatically changes the expression and can miss stemmed terms.
Scoped catalog queries apply the same library predicate to native counts and rows
before pagination; the DQL cursor path adds matching with `AND` to preserve scope.
On 2026-10-04, the disposable functional runner (PostgreSQL 18.4, PGroonga 4.0.5)
passed [catalog scope checks](../../../../tests/Functional/Catalog/Infrastructure/Doctrine/Repository/CatalogReadScopeRepositoryTest.php)
for native counts/results, cursor reads, shared artists, and related metadata;
[compatibility coverage](../../../../tests/Integration/PgroongaSearchCompatibilityTest.php)
verified explicit prefix matching, indexed tuple scores, and pagination.
Both files passed again on 2026-11-16 on a 4.1.0 build (PostgreSQL 18.7).
The scope fixture also runs EXPLAIN with existing indexes; its small dataset is
not a performance benchmark. Review both consumers, result hydration, empty
queries, pagination and ranking.
Do not replace PGroonga with LIKE/ILIKE/trigrams or reinterpret query syntax as
a mechanical SQL cleanup. Test representative supported languages, normalization,
stemming, punctuation, prefix queries and relevance before changing search behavior.

The initial migration also creates public-ID GIN indexes with `gin_trgm_ops`.
[TrigramSimilarity](../../../../src/Shared/Infrastructure/Doctrine/DQL/TrigramSimilarity.php)
emits `similarity()`. PGroonga and pg_trgm serve different query contracts; validate
index eligibility for the emitted predicate, not just the presence of an index.
See [PostgreSQL 18 pg_trgm](https://www.postgresql.org/docs/18/pgtrgm.html).
`citext` is a physical type with a registered DBAL conversion; inspect comparison
and uniqueness semantics before replacing it with plain text or lower-case SQL.
Compare a CITEXT column with plain equality and an untyped string parameter:
pdo_pgsql sends string parameters untyped, so PostgreSQL infers `citext` and
`uniq_users_email` serves the lookup case-insensitively. `LOWER(col) = LOWER(:p)`
and a parameter typed or cast as `text` both resolve to `text = text`; the first
needs a sequential scan, the second is also case-sensitive. Verified 2026-10-07 on
PostgreSQL 18.4 (disposable `baander-database:latest`, 100k rows) and by
[UserEmailLookupTest](../../../../tests/Functional/Auth/UserEmailLookupTest.php),
which asserts index scans through `pg_stat_get_xact_numscans`
(`pg_stat_xact_user_indexes` does not exist).
`users.email` is the only CITEXT column in application tables
(verified in the consolidated schema on disposable PostgreSQL 18.4 on 2026-10-17).
Reset and verification tokens and OAuth client secrets are hashed with SHA-256 in PHP.
The initial migration seeds two schedules with independent public IDs generated
from pgcrypto's [random bytes](https://www.postgresql.org/docs/18/pgcrypto.html#PGCRYPTO-RANDOM-DATA-FUNCS):
it takes 21 base64 symbols from `gen_random_bytes(16)` and translates the alphabet
for 126 random bits. Application writers supply non-null, unique public IDs;
these text columns have no database defaults. Scheduler occurrences retain their
job's public ID. Internal UUID keys remain separate from public IDs.

On 2026-10-17, the captured 53-migration chain and consolidated initial migration
were run in separate databases on disposable `baander-database:latest`.
The server reported PostgreSQL 18.4 with `citext` 1.8, `ltree` 1.3,
`pg_stat_statements` 1.12, `pg_trgm` 1.6, `pgcrypto` 1.4, `pgroonga` 4.0.5 and
`uuid-ossp` 1.1. Both catalogs contained 80 application tables, five PGroonga
indexes with the same tokenizer, normalizer and stem-filter options, and nine
trigram indexes. These observations establish enablement in those disposable
databases; they do not establish development or deployed database state, usable
`pg_stat_statements` statistics, or search performance.

## Clean up leftover Groonga objects

A PGroonga index keeps its data in Groonga objects named after its relfilenode
(`Sources<N>`, `Lexicon<N>_<n>` and their columns), in one Groonga store per database,
outside PostgreSQL's catalog and transactions. PGroonga has no drop hook: `DROP INDEX`,
`DROP TABLE`, `DROP SCHEMA ... CASCADE` and a rolled-back `CREATE INDEX` leave the
objects behind. `PGrnRemoveUnusedTables` is the only code that removes them. It runs in
every `VACUUM` and `ANALYZE` of a table with a PGroonga index, autovacuum's and
autoanalyze's included, and in `pgroonga_vacuum()`. It scans every `Sources*` object in
the database and, for each relfilenode that resolves to no relation, removes the
lexicon columns, then `Sources`, then the lexicon tables. The mechanism was read on
2026-11-16 in the 4.0.5 and 4.1.0 sources; the observations below come from
disposable containers of the two 2026-11-16 builds, on initdb defaults.

- **Leftovers alone are harmless.** A throwaway schema holding the five project tables
  with their PGroonga indexes, built in autocommit, used and dropped, left five
  `Sources` and five `Lexicon` objects. A fresh connection's insert and index search on
  `public.songs` succeeded before and after `pgroonga_vacuum()` removed them, on both
  versions.
- **An uncommitted index loses its data before 4.1.0.** 4.0.5 resolves the relfilenode
  under the cleaning session's snapshot, so an index that another session has built and
  not committed counts as unused and loses its objects. After its commit the index fails
  with SQLSTATE 22023 `pgroonga: PGrnLookupWithSize: object isn't found: <SourcesN>`
  until `REINDEX INDEX` repairs it
  ([issue 1013](https://github.com/pgroonga/pgroonga/issues/1013), reported on 4.0.6).
  PGroonga 4.1.0 (released 2026-10-09,
  [PR 1014](https://github.com/pgroonga/pgroonga/pull/1014)) re-checks a miss against
  `pg_class` with a dirty snapshot, which sees uncommitted rows. Two sessions with
  autovacuum off reproduce it: A builds a table and index in an open transaction, B runs
  `ANALYZE` of a committed PGroonga table, `VACUUM` or `pgroonga_vacuum()`, and A commits
  and inserts. On 4.0.5 all three orders failed; on 4.1.0 all three passed. With
  autovacuum alone (naptime 1 s, A open for 10 s), a worker vacuumed `songs` 1.3 s after
  the churn on 4.0.5, and A's insert after its commit failed; on 4.1.0 A's objects
  survived ten autovacuum runs of `songs`. An open transaction pins dead tuples, so
  autovacuum re-processes the same table every naptime while it lasts. On 4.0.x, any
  migration that builds a PGroonga index while autovacuum runs can lose it, the initial
  one among them, and so can every test schema that
  [FinalSchemaBootstrap](../../../../tests/Support/FinalSchemaBootstrap.php) replays in
  one transaction.
  [PgroongaUncommittedIndexTest](../../../../tests/Integration/PgroongaUncommittedIndexTest.php)
  runs the three orders; on 2026-11-16 its cases failed on 4.0.5 and passed on 4.1.0.
- **Two cleanups still collide in 4.1.0.** Nothing serializes cleanups, and the
  removal loop is unchanged in 4.1.0 and on upstream `main`. With autovacuum off, 50
  dropped schemas and two sessions released into `pgroonga_vacuum()` at once, 30 of 30
  iterations collided on each version. Exactly one cleanup failed each time. On 4.0.5
  it always failed with 58030
  `failed to remove column: <Lexicon<N>_0.index>: ... failed to stat`; on 4.1.0 it did
  so 28 times and twice failed with 22023
  `failed to remove: <SourcesN>: [object][remove][force] nonexistent object`. The
  other cleanup finished, nothing was left, and a cleanup run alone afterwards
  succeeded: collisions converge, but the error reaches whichever statement ran the
  losing cleanup.
- **Classify by message, not SQLSTATE.** 22023 covers both the lost uncommitted index
  and, on 4.1.0, one collision form; 58030 is the other collision form.
- **On 4.0.5, `pgroonga_list_broken_indexes()` raises instead of listing.** It raised
  the same `object isn't found` error as the broken index, as did `EXPLAIN` of a search
  through it. It is untested against a broken index on 4.1.0; treat an error from it as
  a broken index and confirm with a search through each PGroonga index.

The test databases keep autovacuum on, so a test's own cleanup can meet an autovacuum
cleanup. A test that runs `ANALYZE` or `VACUUM` on a table with a PGroonga index, or
`pgroonga_vacuum()`, first calls
[PgroongaLeftovers::clear()](../../../../tests/Support/Doctrine/PgroongaLeftovers.php).
Inside `Connection::transactional()` (its own transaction in autocommit, a savepoint
inside the caller's), the helper lists every table with a PGroonga index in every
schema in OID order, locks them all with one
`LOCK TABLE ... IN SHARE UPDATE EXCLUSIVE MODE`, and runs `pgroonga_vacuum()`. That
lock conflicts with itself. Autovacuum, except against wraparound, runs with
`VACOPT_SKIP_LOCKED` and skips a table it cannot lock at once; PostgreSQL cancels a
worker that already holds the lock (57014 `canceling autovacuum task`) once the helper
has waited `deadlock_timeout`. With `pgroonga.enable_wal` off the cleanup functions
have no `CHECK_FOR_INTERRUPTS`, so the cancel cannot land partway through the cleanup
itself. The locks last until the caller's transaction ends, so an `ANALYZE` the caller
runs in it finds nothing to remove. On 4.1.0 with naptime 1 s, holding the locks for
15 s logged 28 `55P03` `skipping vacuum ... lock not available` lines, left the
autovacuum counters unchanged, and let workers resume within 1.3 s of the release. In
20 iterations with 50 leftovers, the helper's lock request cancelled the worker
processing `songs` every time and waited exactly `deadlock_timeout` (1 s by default),
and its `pgroonga_vacuum()` raised no error and left no orphans. On 4.0.5 the helper
itself deletes the objects of another session's uncommitted index, as every cleanup
there does.

[PgroongaCleanupConventionTest](../../../../tests/Unit/Shared/Infrastructure/Doctrine/PgroongaCleanupConventionTest.php)
fails when a PHP file under `tests/` has an `ANALYZE` or `VACUUM` string literal at a
statement start, or a `pgroonga_vacuum(` call, and neither calls the helper nor appears
on its reviewed list with an exact count and a reason. The list holds
`UserEmailLookupTest`, `RetagLockOrderTest`, `playback-snapshot-cost.php` and the
guard's own fixture, none of which reaches a table with a PGroonga index. `src/` and
`migrations/` run no such statement. To count leftovers, read the `Sources<N>` names in
`pgroonga_command('table_list')` and keep those whose N matches no
`pg_class.relfilenode`, as
[PgroongaLeftoversTest](../../../../tests/Integration/PgroongaLeftoversTest.php) does.
The query reads `pg_class` under the session's snapshot, so another session's
uncommitted index counts as a leftover there.

In a full backend run on 4.0.5 with `log_autovacuum_min_duration = 0` (seed 41160,
13,028 tests), autovacuum processed PGroonga-indexed `public` tables 27 times in one
stack lane and 38 times in the other; each of those runs is a cleanup. Five full runs
on 4.1.0 with the helper and the guard (seeds 20261116, 7341, 99881, 424242 and 31337,
13,028 tests each, 2026-11-16) passed with no `pgroonga:` ERROR line in any lane's
PostgreSQL log. When scanning such a log, expect the helper test's `55P03` lines,
which name `pgroonga_leftovers_<hex>` schemas and so match a loose `pgroonga` search,
`57014` lines where the helper waited for a worker, and `55P03` `skipping vacuum` lines
when autovacuum logging is on.

## Diff and performance checks

Preserve the custom platform, mapping attributes and migration-managed indexes
when investigating repeated diffs. The schema manager hides `_trgm` and selected
named indexes and does not capture every PGroonga option; clean diff/schema
validation cannot establish their presence, tokenizer or health. Inspect
`pg_get_indexdef`, operator classes and `reloptions` directly. Never drop PGroonga
or erase index attributes merely to eliminate churn.

On a disposable representative dataset, EXPLAIN the actual parameterized search
query and compare semantics before/after. EXPLAIN ANALYZE executes the query;
use it only for suitable read-only workload or explicitly isolated work. A small
table's sequential plan is not proof of a broken index.

PGroonga 4.0.5 estimates a scan of its whole index at zero cost. For an equality
predicate its operator classes cannot serve, the planner may still choose an
unqualified PGroonga bitmap or index-only scan with a `Filter` over the B-tree that
has an `Index Cond`. Verified 2026-10-07 on PostgreSQL 18 with PGroonga 4.0.5
(disposable container) and not rechecked on 4.1.0: it happens on near-empty tables
with fresh statistics, on stale statistics after deletes, and when the predicate value
is estimated to be frequent. With current statistics on a populated table, the B-tree
wins. No `pgroonga.*` setting changes this. A test that asserts a B-tree serves a
lookup on a column that also has a PGroonga index must disable bitmap and index-only
scans and assert the `Index Cond`, as
[CoveredIndexPersistenceTest](../../../../tests/Integration/CoveredIndexPersistenceTest.php)
does. Test fresh migrations and upgrades with the real extension-capable image; ORM
SchemaTool alone neither installs extensions nor reproduces all custom migration
indexes. Report what the test environment actually covered.

## Maintain this inventory

Verification on 2026-10-02: the disposable messaging runner passed 20 tests,
including [PGroonga compatibility coverage](../../../../tests/Integration/PgroongaSearchCompatibilityTest.php).
That fixture then required an installed version of at least 2.0.4, the tuple-score
API's minimum. Since 2026-11-16 it requires 4.1.0 in its own case,
`testInstalledExtensionKeepsTheIndexesOfUncommittedTransactions`, whose failure
message names the cleanup fix. The messaging gate runs the fixture
([Qualification](../../../../packages/baander-dev-qualification/src/Fixtures/Messaging/Qualification.php)),
so that gate also fails on an image older than 4.1.0. The fixture also verifies a
valid v2 text operator-class index with the mapped stem/Ngram options, production
DQL matching, and positive indexed tuple scores with pagination.
Its small-fixture scoring check disables sequential scans to exercise the index;
it is not a planner/performance benchmark or evidence about deployed databases.

When an authorized PostgreSQL task reveals a new extension or changes an existing
one, update the relevant inventory entry here after verifying the evidence.
Do not broaden the task into an extension installation, upgrade, removal or
production inspection. Preserve the existing authorization and connection boundaries.

Record the extension's role and distinguish four states: packaged in the image,
available to PostgreSQL, enabled in a specific database, and used by actual
mapping/query/index code. Attach repository paths, observed server/extension
versions, relevant primary documentation, and the bounded checks or tests run.
State which environment supplied runtime evidence; build arguments alone establish
only intended packages. Mark uncertain versions, consumers or effective settings
as unknown rather than filling gaps from assumptions.

Keep entries short and decision-relevant. Explain type conversions, index methods,
operator classes, query semantics or upgrade limits only when they affect this
project. Recheck existing claims when dependency/image changes invalidate them;
remove superseded guidance after preserving any still-relevant compatibility
constraint. Report the documentation change and unresolved evidence limits with
the task result. Do not add secrets, transient database dumps or unverified
catalog output to the skill.
