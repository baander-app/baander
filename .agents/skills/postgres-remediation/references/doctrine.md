# Doctrine in Baander

Use this reference for mapping, schema drift, migration and repository work. Paths
below are relative to this reference. Recheck them after dependency upgrades.

## Identify the environment

[composer.lock](../../../../composer.lock) records DBAL 4.4.3, ORM 3.6.6,
DoctrineBundle 3.2.2, Migrations 3.9.7 and MigrationsBundle 4.0.0 at this review;
the installed vendor tree matched the lock on 2026-10-10. Inspect both; a fresh
lockfile install and the current runtime need not match.
DBAL 4.4.3's PostgreSQL exception converter leaves lock timeout SQLSTATE `55P03`
as a generic `DriverException`; do not assume `LockWaitTimeoutException` applies.
Check `getSQLState()` and the installed converter when classifying database errors.
The disposable PostgreSQL 18 lease contention test verifies this behavior in
[WorkerDeploymentLeaseTest](../../../../tests/Integration/WorkerDeploymentLeaseTest.php).
Use `composer show --locked doctrine/orm` and `composer show doctrine/orm`, repeating
for the relevant packages. Read installed implementation for generated SQL claims.

[doctrine.yaml](../../../../config/packages/doctrine.yaml) maps attributes under
`src`, uses the underscore naming strategy, and excludes `domain_event_outbox`
from schema introspection. Domain aggregates are separate from Doctrine entities;
read the repository's conversion and synchronization methods, not just domain fields.
`mapping_types: timestamptz: datetime_immutable` is reverse database-type mapping;
on its own it does not make a `datetime_immutable` column emit `timestamptz`.

Instants follow one convention: every instant column is `timestamptz` with no
explicit precision (microseconds), mapped as `datetime_immutable`.
`InstantColumnConventionTest` fails on a zone-less or fixed-precision instant
column. The one exception is `doctrine_migration_versions.executed_at`, which stays
`timestamp(0) without time zone`: Doctrine Migrations' `TableMetadataStorage`
parses it with `Y-m-d H:i:s` and drops a non-matching value to null.

[InstantType](../../../../src/Shared/Infrastructure/Doctrine/Type/InstantType.php)
replaces Doctrine's `datetime_immutable` and `datetimetz_immutable`. It declares
`TIMESTAMPTZ`, writes the UTC instant as `Y-m-d H:i:s.uP`, rejects a mutable
`DateTime`, and reads PostgreSQL's text back into a UTC `DateTimeImmutable`.
Every PostgreSQL connection also sets its session `TimeZone` to UTC.
[BaanderDriverMiddleware](../../../../src/Shared/Infrastructure/Doctrine/Platform/BaanderDriverMiddleware.php)
does it on connect, [DedicatedConnectionFactory](../../../../src/Shared/Infrastructure/Doctrine/DedicatedConnectionFactory.php)
adds that middleware to connections built outside DoctrineBundle, and
`RecommendationPoolWorker` sets it on its own PDO connection. Any new connection
that bypasses both must set the zone the same way.

Custom `uuid`, `public_id`, `citext`, `job_status` and the instant types are registered both in
configuration and [CustomTypesRegistrar](../../../../src/Shared/Infrastructure/Doctrine/Type/CustomTypesRegistrar.php),
called by [Kernel::boot](../../../../src/Kernel.php). Swoole's connection creation
can bypass DoctrineBundle's type initialization. New types need compatible
registration and PHP/database conversion, not just an entity attribute. UUID
properties use the project's `Uuid` value object; PublicId emits `TEXT`, not an
inferred fixed-width SQL identifier.

## Generated DDL is evidence, not a verdict

Installed `vendor/doctrine/dbal/src/Platforms/PostgreSQLPlatform.php` emits
`TIMESTAMP(0) WITHOUT TIME ZONE` for datetime and `TIMESTAMP(0) WITH TIME ZONE`
for datetimetz; the project's `InstantType` replaces both immutable variants. `vendor/doctrine/orm/src/Tools/SchemaTool.php` supplies length 255
for a string mapping without length. These are framework choices, not proof the
application requires second precision or a 255-character bound. Apply the
PostgreSQL guidance to the actual data meaning; explain any retained exception.
Changing a mapping alone may not remove platform timestamp precision. Verify
generated SQL and round-trip fractions/timezones before choosing a scoped fix.

PostgreSQL rejects `ALTER COLUMN … TYPE` on a column that a generated column
reads: drop and re-add the generated column in the same migration (verified on
PG 18.4 with `job_monitors.duration_microseconds`, Version20261006340000).
Relaxing `timestamptz(0)` to `timestamptz` keeps stored values without a rewrite;
re-adding a STORED column rewrites the table. On PostgreSQL 18, `ALTER COLUMN …
TYPE` still rebuilds a partial index whose predicate reads the altered column
when the table itself is not rewritten (the three `domain_event_outbox` partial
indexes in Version20261010180000), and converting a zone-less column with
`USING … AT TIME ZONE 'UTC'` rewrites the table and all its indexes. Every such
ALTER takes ACCESS EXCLUSIVE; that migration sets `lock_timeout` so it fails
rather than queueing behind a running process.

`mapping_types: timestamptz: datetime_immutable` introspects every timestamptz
column as `datetime_immutable`, so schema comparison cannot see timestamptz
precision; `InstantColumnConventionTest` checks it in the catalog instead.
Comparison does see a zone-less column: it introspects as `datetime`, which
declares `TIMESTAMP(0) WITHOUT TIME ZONE` where `InstantType` declares `TIMESTAMPTZ`.

Doctrine's stock `DateTimeImmutableType` writes `Y-m-d H:i:s`, with no offset and
no fraction. `InstantType` replaces it for every mapping and typed bind, but a
hand-written bind that formats its own string bypasses any type. Bind instants with
the type (`Types::DATETIME_IMMUTABLE`) or as a UTC `Y-m-d H:i:s.uP` string, never
as `Y-m-d H:i:s`, ATOM or `'c'`. `InstantBindConventionTest` fails on those formats
in database code, and on the mutable and `tz` instant type names anywhere in `src/`.
Ranges over instants are half-open (`[day start, next day start)`).
When widening timestamp precision, widen cursor sort values too
(`JobMonitorService::extractSortValue`): a whole-second cursor re-includes its own row.

For IDs, distinguish application-assigned UUID with `GeneratedValue('NONE')`,
explicit `SEQUENCE`/`IDENTITY`, and `AUTO`. Installed ORM's
`Mapping/ClassMetadataFactory.php::determineIdGeneratorStrategy` checks configured
preferences first; DBAL 4 PostgreSQL defaults to IDENTITY, whose current platform
emits `GENERATED BY DEFAULT AS IDENTITY`. Historical serial/sequence columns remain
until deliberately migrated. Do not apply DBAL 3 serial advice to DBAL 4, change
UUID identifiers to integers, or rewrite an applied initial migration.

## Understand what schema comparison omits

[BaanderDriverMiddleware](../../../../src/Shared/Infrastructure/Doctrine/Platform/BaanderDriverMiddleware.php)
selects the custom PostgreSQL platform; the configured schema-manager factory
selects [BaanderPostgreSQLSchemaManager](../../../../src/Shared/Infrastructure/Doctrine/Platform/BaanderPostgreSQLSchemaManager.php).
The platform supports index methods, operator classes and `WITH` options. The
schema manager preserves some method/operator-class metadata, but does not
round-trip all options: it resolves the first operator-class OID and does not
capture PGroonga `WITH` settings. Compare catalog definitions directly.

It hides all index names ending `_trgm` plus the two cases in
[UnmanagedCustomIndex](../../../../src/Shared/Infrastructure/Doctrine/Platform/UnmanagedCustomIndex.php)
(`idx_images_imageable`, `idx_albums_cover_image_null`). These remain migration-managed
physical indexes. Exclusion is not evidence of existence or correctness. Ordinary
partial indexes can be represented with `options: ['where' => ...]`; do not label
every partial index unsupported or add unrelated objects to the exclusion list.
Check SchemaTool's actual unique-index deduplication when indexes share columns.
See [extension checks](extensions.md) before accepting index deletion/recreation.

DBAL 4.4.3 does not model generated columns. PostgreSQL stores their expressions
in `pg_attrdef`, which DBAL would read as column defaults, so the platform's
`getDefaultColumnValueSQLSnippet()` excludes rows with `attgenerated <> ''`. Map
a stored generated column with `columnDefinition` (ignored by `columnsEqual`),
`insertable: false`, `updatable: false` and no default, as
`JobMonitorEntity::$durationMicroseconds` does. Schema comparison does not check
the generation expression; compare it in the catalog directly.

## Migrations and disposable verification

[MigrationVersionComparator](../../../../src/Shared/Infrastructure/Doctrine/MigrationVersionComparator.php)
and [doctrine_migrations.yaml](../../../../config/packages/doctrine_migrations.yaml)
order legacy numbered classes before later timestamped migrations. Filenames are
not necessarily migration class identities. Preserve executed FQCN metadata;
changing sort order must not rename/reexecute applied migrations. Check current,
latest and reverse plans; unknown formats require an ordering decision.
Migrations 3.9.7 only warns about executed migrations the directory lacks, and
`--no-interaction` proceeds, which would rerun migrations applied under former
class names; [UnknownExecutedMigrationGuard](../../../../src/Shared/Infrastructure/Doctrine/EventListener/UnknownExecutedMigrationGuard.php)
makes `doctrine:migrations:migrate` refuse instead. Reconcile such a history with
`doctrine:migrations:version` before migrating. Some
historical down migrations are irreversible or destructive: a reverse plan
alone does not prove rollback safety.

After explicitly selecting an authorized/disposable database, useful commands are:

```bash
php bin/console doctrine:mapping:info --env=test
php bin/console doctrine:schema:validate --env=test
php bin/console doctrine:migrations:status --env=test
php bin/console doctrine:migrations:list --env=test
php bin/console doctrine:migrations:migrate --dry-run --no-interaction --env=test
```

Check command help for installed options. Do not infer isolation from `--env=test`:
the test config has no enabled database-name suffix. Dry-run/console boot may
touch caches or metadata; use a disposable DB for strict no-mutation work.
`doctrine:migrations:diff` writes a candidate PHP file; review every statement.
Never use `schema:update --force` as a deployment shortcut. Test all migrations
on a fresh extension-capable instance, repeat to prove no pending reexecution,
and test upgrading representative populated data. SchemaTool can create focused
fixtures but does not test migration order, extension setup or hand-written SQL.

## Unit of Work, transactions and proxies

Read association owning sides, nullable joins, cascade settings and repository
sync methods together. Persisting a domain object is not ORM persistence; adapters
must synchronize the mapped entity and managed associations. ORM `find`/queries
can reuse managed identity-map objects; a database-authoritative repository is
not a promise to refresh every managed instance. Raw SQL/bulk updates bypass the
Unit of Work; check stale managed state before adding selective clear/refresh.
[Doctrine ORM 3.6 object lifecycle](https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/working-with-objects.html)
explains these boundaries.

`LibraryRepository` claims a library in one statement (a `SELECT … FOR UPDATE`
CTE feeding a conditional `UPDATE`), so a refused claimant reads the winner's
claim kind from the same locked row, and `releaseClaim()` uses PostgreSQL 18
`RETURNING old.<column>` to report what it released. Verified on PostgreSQL 18
by the two-session races in `tests/Integration/LibraryScanClaimTest.php`.
`claimDelete()` runs that statement in a transaction after
`pg_advisory_xact_lock(hashtextextended(<library id>, 20261010120000))`; import
writes take the shared form first (`liveClaimKindForImport()`), then read the
claim in a separate statement so its READ COMMITTED snapshot postdates the lock.
Verified by the two-session import/delete tests in the same file.

For example, [AccessTokenRepository](../../../../src/Auth/Infrastructure/Repository/OAuth/AccessTokenRepository.php)
honors `save(..., false)` and clears after bulk revocation. Preserve the flush
parameter. A flush inside an outer transaction is not a commit; do not publish
cache authorization status or external effects on that basis.
[DoctrineTransaction](../../../../src/Shared/Infrastructure/Doctrine/DoctrineTransaction.php)
flushes within the shared DBAL transaction, clears an open manager on rollback,
and releases only the current coroutine's closed ContextualProxy manager.
Do not replace that path with a blanket reset of the shared proxy/service pool.
ORM native lazy entity objects (DoctrineBundle enables them) and Swoole contextual
service proxies are different mechanisms; tests must exercise the relevant one.

CLI Messenger also needs an explicit pooled-service release boundary. The installed
Swoole `StatefulServicesPass::reduceServiceResetters()` removes pooled services from
Symfony's ordinary reset list. `ResetServicesListener` alone therefore left a closed
manager in the next delivery in a real PostgreSQL test. The application
[WorkerServicePoolResetSubscriber](../../../../src/Shared/Infrastructure/Messenger/WorkerServicePoolResetSubscriber.php)
releases only the current execution context after delivery and at stop, before
Symfony resets remaining services. The pool discards a closed manager through its
stability checker; it must not replace or unset the shared proxy. Preserve this
boundary when extracting the independent worker runtime and recheck it after bundle
upgrades. [CoverExtractionPersistenceTest](../../../../tests/Integration/CoverExtractionPersistenceTest.php)
uses the production kernel/proxy, real failed flush, both reset subscribers and an
independent observer to verify same-process retry and committed recovery.

[DAMA configuration](../../../../config/packages/dama_doctrine_test_bundle.yaml)
and [phpunit.xml.dist](../../../../phpunit.xml.dist) wrap functional tests in static
connections/transactions. They are useful for isolation but cannot alone prove
commit visibility to a separate connection. Use independent writer/observer
connections for commit/rollback tests, clear managed state deliberately, and
reset closed managers through the actual runtime mechanism. See
[AccessTokenCacheTransactionTest](../../../../tests/Integration/AccessTokenCacheTransactionTest.php)
for a focused ORM/SchemaTool fixture; its standalone setup explicitly registers
types, naming strategy and native lazy objects. That fixture does not substitute
for the production kernel/middleware, migration plan or full extension stack.
