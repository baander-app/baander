# Extension-aware persistence and search

Read alongside [Doctrine](doctrine.md) when reviewing search SQL, custom column
types, index drift or a PostgreSQL runner. Paths are relative to this reference.

## Establish installed capabilities

[docker/postgres/Dockerfile](../../../../docker/postgres/Dockerfile) and
[docker-compose.yml](../../../../docker-compose.yml) build PostgreSQL 18 with
PGroonga package 4.0.5-1, Groonga stem filter and MeCab tokenizer packages, plus
PostgreSQL contrib. These are build intentions, not proof of the running image,
database extension version or enabled tokenizer. Do not substitute a stock
PostgreSQL container for extension-dependent migration/search verification.

[Version001_InitialSchema](../../../../migrations/Version001_InitialSchema.php)
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
table's sequential plan is not proof of a broken index. Test fresh migrations
and upgrades with the real extension-capable image; ORM SchemaTool alone neither
installs extensions nor reproduces all custom migration indexes. Report what the
test environment actually covered.

## Maintain this inventory

Verification on 2026-10-02: the disposable messaging runner passed 20 tests,
including [PGroonga compatibility coverage](../../../../tests/Integration/PgroongaSearchCompatibilityTest.php).
That fixture verifies an installed version at least 2.0.4 (the tuple-score API's
minimum), a valid v2 text operator-class index with the mapped stem/Ngram options,
production DQL matching, and positive indexed tuple scores with pagination.
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
