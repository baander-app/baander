---
title: Timestamp Convention - Plan
type: refactor
date: 2026-10-10
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Timestamp Convention - Plan

## Goal Capsule

- **Objective:** every recorded time in Baander is the real instant, to the microsecond, whichever process, time zone or write path recorded it; ordering, paging and comparisons by time are exact, and API clients see timestamps in the same format as today.
- **Means:** one Doctrine type for instants (KTD1), every instant column as plain `timestamptz` (KTD2), and the write, compare and cursor paths that bypass the type fixed (KTD4, KTD5).
- **Authority:** this plan's Product Contract, then AGENTS.md and `.agents/rules/`, then the postgres-remediation skill (`.agents/skills/postgres-remediation/`), then the Key Technical Decisions. The pre-release policy applies: no compatibility layers.
- **Stop conditions:** stop and ask if the migration cannot convert a column without losing or shifting stored instants, or if keeping the API format turns out to require storing whole seconds. Do not migrate a database whose recorded migration versions do not match the migration directory (see Assumptions).
- **Execution profile:** backend (Symfony 7, PHP 8.5, Swoole, Doctrine ORM 3 / DBAL 4, PostgreSQL 18); one schema-wide migration and the type and SQL work go to the PostgreSQL specialist; no UI change.
- **Ships:** local commits on `plan/item3-timestamps`; push only when the user asks.

---

## Product Contract

### Summary

Every column that records an instant becomes PostgreSQL `timestamptz` at its native microsecond precision, converted in place by one forward migration. A project Doctrine type replaces `datetime_immutable`, so ORM writes carry the offset and microseconds and reads come back in UTC. The SQL, cursor and API paths that bypass the type are fixed, and a test guards the convention.

### Problem Frame

Baander records instants three ways. 125 columns are `timestamptz(0)`, whole seconds; 38 are plain `timestamptz`; 11 application columns are `timestamp(0)` without a time zone. Doctrine's `datetime_immutable` type, used by 148 mappings, writes `Y-m-d H:i:s`: no offset and no fraction. Its platform declares `TIMESTAMP(0)`.

The result:
- Two events in the same second look simultaneous, so ordering by time is arbitrary within a second.
- A value written without an offset means whatever the session's time zone says it means. A probe wrote a non-UTC PHP time through the current type into a non-UTC session and stored an instant 3 hours 45 minutes off. Today correctness rests on two defaults: PHP's zone is UTC and the database server's is `Etc/UTC`.
- Hand-written SQL binds some instants with offset and microseconds and others without, so the ORM and SQL disagree on the same column.
- Once columns hold microseconds, a whole-second cursor repeats or skips rows within a second (the song "added" sort does this), and a few API fields that pass raw PostgreSQL text gain fractional digits.

The project's PostgreSQL guidance already prescribes `timestamptz` without explicit precision (`.agents/skills/postgres-remediation/references/remediation.md`) and records that the current mapping hides precision from schema comparison (`references/doctrine.md`).

### Requirements

**Storage**

- R1. Every column that records an instant is `timestamptz` with no explicit precision; `doctrine_migration_versions.executed_at`, owned by Doctrine Migrations, is the one documented exception, and calendar `date` columns stay `date`.
- R2. Existing local data converts in place: whole-second values keep their instant, and zone-less values are read as UTC.

**Writes and comparisons**

- R3. Every write path, ORM or SQL, stores the instant with its offset and microseconds, independent of the PHP time zone and the database session's time zone.
- R4. Ordering, cursor pagination and range or "since" comparisons by time are exact to the microsecond: a cursor never repeats or skips a row within a second.

**API**

- R5. API JSON, the OpenAPI spec and the web client keep today's timestamp format (`Y-m-d\TH:i:sP`, seconds, `+00:00`); fields that today return raw PostgreSQL text return that format.

**Guard and close-out**

- R6. A test fails when an instant column regresses to a zone-less type or an explicit precision, when the project type stops being the registered `datetime_immutable` (and `datetimetz_immutable`) under the console or the Swoole kernel, or when application code binds an instant as a zone-less or whole-second string.
- R7. The PostgreSQL skill's Doctrine notes describe the new convention, and ROADMAP item 3 is closed.

### Key Decisions

- **Microsecond `timestamptz`, no explicit precision.** (session-settled: user-approved — chosen over keeping whole seconds or a fixed precision such as milliseconds: the project's PostgreSQL guidance says to avoid explicit precision.) Governs R1.
- **API output keeps its current whole-second format.** (session-settled: user-approved — chosen over exposing fractional seconds in API timestamps.) Governs R5. Consequence recorded under Scope Boundaries: a client that echoes a whole-second `createdAt` back as `since` can receive rows from that same second again.
- **One forward migration that keeps local data; zone-less values are UTC.** (session-settled: user-approved — chosen over rewriting migration history.) Governs R2. Verified: every zone-less column is written in UTC (PHP's default zone is UTC; the server runs `Etc/UTC`, and `SHOW TIME ZONE` on the local `baander-postgres` returned `Etc/UTC` from its configuration file on 2026-10-10).

### Scope Boundaries

- Calendar `date` columns are unchanged.
- No fractional seconds in API output (Key Decisions).
- "Since" filters stay exclusive at the stored precision. With whole-second API output, a client that sends a notification's `createdAt` back as `since` receives notifications from the rest of that second again: at-least-once, not lost. No client in this repository does so; the web client does not use `since`. Evidence that would change this: a client that relies on `since` for exactly-once delivery.
- Cursors issued before the migration encode whole seconds; after it, such a cursor can repeat one row once. No compatibility path (pre-release policy).
- No change to vendor-owned table definitions (Messenger `failed_messages`, the outbox and scheduler tables created by migrations are converted like any other table).
- The local dev database (`baander` in `baander-postgres`) is not brought current here: it records the pre-rename migration names and is about 40 migrations behind (last run 2026-07-15), so it cannot reach this migration until its history is reconciled or it is reset. That is a separate ROADMAP entry (user decision, 2026-10-10); R2 is proven on databases at the current migration version.
- `ui/rn` and Essentia are out of scope with no end date.

---

## Planning Contract

### Key Technical Decisions

- KTD1. **A project type overrides Doctrine's `datetime_immutable`.** It declares `TIMESTAMPTZ` without precision, writes `Y-m-d H:i:s.uP` after converting the value to UTC, and reads into a UTC `DateTimeImmutable`; reads in UTC keep `format(DATE_ATOM)` output at `+00:00` (R5). Overriding the built-in name, rather than adding a new one, leaves the 148 mapping attributes alone and also catches DQL-inferred and typed binds (`SongRepository::findUpdatedAfter`, `ServerInstanceRepository::findStale`, `ImageRepository`, the lyrics and push-subscription repositories). It is registered in `config/packages/doctrine.yaml` and in `src/Shared/Infrastructure/Doctrine/Type/CustomTypesRegistrar.php`, because the Swoole kernel bypasses DoctrineBundle's type initialisation (`CustomTypesRegistrar` already uses `Type::overrideType` for an existing name); standalone test registrations go through the registrar. `mapping_types: timestamptz: datetime_immutable` stays and now maps to the project type. The same class is also registered as `datetimetz_immutable`, whose stock implementation keeps the offset but drops microseconds, so binds typed with it (today the token repositories) become exact too. Rejected: a new type name — 148 attribute edits plus explicit types at three DQL sites and three typed binds, and any future mapping that uses plain `datetime_immutable` would silently regress.
- KTD2. **One forward migration after `Version20261010110000`.** It relaxes the 125 `timestamptz(0)` columns to `timestamptz` (catalog change, no table rewrite) and converts the 11 zone-less application columns with `USING col AT TIME ZONE 'UTC'` (rewrites five small tables: `webhooks`, `webhook_delivery_logs`, `eq_device_profiles`, `user_theme_moods`, `recommendation_jobs`), handling their defaults (drop `NULL::timestamp` defaults; drop and re-set defaults where a conversion requires it). It sets a `lock_timeout` so it fails rather than waits behind a running worker; the operator note says to stop the web server and worker first. `down()` converts back with explicit `AT TIME ZONE 'UTC'` and documents that fractions are lost. `doctrine_migration_versions.executed_at` stays zone-less: Doctrine Migrations parses it as `Y-m-d H:i:s` and reads a `timestamptz` value as null. No generated column, view, trigger, partition or PGroonga index depends on these columns; the three outbox partial indexes that filter on a converted column are rebuilt by PostgreSQL.
- KTD3. **Every connection pins its session time zone to UTC.** `BaanderDriverMiddleware` sets `TimeZone` to UTC on connect. The connections that bypass DoctrineBundle get it too: the direct PDO connection in `RecommendationPoolWorker` sets the zone itself, and the six scheduler stores that build their own connection in `fromDsn` (`DoctrineSchedulerOccurrenceDispatchStore`, `…OccurrenceMaterializer`, `…OccurrenceExecutionStore`, `…RecoveryScheduleStore`, `…ManualOccurrenceRecorder`, `DoctrineSchedulerWorkerAuthority`) build it with a DBAL configuration that includes the middleware. With `timestamptz` everywhere and offsets on every bind, the zone matters only for PostgreSQL text and zone-less functions (`::date`, `date_trunc` without a zone); pinning it makes those deterministic instead of depending on the server's default.
- KTD4. **Hand-written binds use the instant type or an offset literal, never a zone-less string.** Fix `RecommendationPoolWorker` (binds `Y-m-d H:i:s`), the token repositories (bind `DATETIMETZ_IMMUTABLE`, which drops microseconds; KTD1's override also covers them), the outbox writers that bind `format(DATE_ATOM)` with an offset but no fraction (`OutboxRepository::append` `created_at`, `OutboxRepository::recordFailure` and `NotificationDeliveryRepository::recordFailure` `next_attempt_at` and `dead_lettered_at`), and any site an audit of binds formatted with `ATOM`, `'c'`, `RFC3339` or `Y-m-d H:i:s`, `::date`, `date_trunc` without a zone, `BETWEEN` and `<=` upper bounds turns up. A test fails when application code under `src/` formats an instant bind as `Y-m-d H:i:s` or binds with `Types::DATETIMETZ_IMMUTABLE` or `Types::DATETIME_MUTABLE`. The existing `Y-m-d H:i:s.uP` literals (`JobMonitorService::instant`, `NotificationRepository`, the OAuth code repositories, the scheduler writers) already follow the convention.
- KTD5. **Cursors and API fields format instants explicitly.** The song "added" cursor, which today encodes `format('c')` (whole seconds), encodes `Y-m-d H:i:s.uP` instead, following `JobMonitorService::extractSortValue`. `TranscodeJobDto::fromArray`, `TranscodeSessionDto::fromArray` and `RecommendationInsightsRepository` (`last_generated_at`, also printed by `RecommendationStatsCommand`) parse the PostgreSQL value and emit the API format instead of passing raw text.
- KTD6. **A catalog test guards the convention.** Schema comparison cannot see `timestamptz` precision or time zone (introspection renders both `timestamp` and `timestamptz` as `TIMESTAMP(0) WITHOUT TIME ZONE`), so a test queries `information_schema.columns` and fails on an instant column with a zone-less type or an explicit precision, allowing only `doctrine_migration_versions.executed_at`. It sits beside `tests/Integration/DatabaseNamingConventionTest.php`.

### Assumptions

- The deployment runs the migration with the web server and worker stopped (KTD2); there is no production deployment (pre-release).
- A database whose recorded migration versions do not match the migration directory is not migrated. Doctrine Migrations reports previously executed migrations that are missing from the directory; implementation verifies that this stops `migrations:migrate` non-interactively, and adds a guard only if it does not.

### Sources

- Inventory of a fully migrated PostgreSQL 18.4 database (this plan's research): 177 date and time columns — 125 `timestamptz(0)`, 38 `timestamptz`, 12 `timestamp(0)` (11 application columns plus `doctrine_migration_versions.executed_at`), 2 `date`. A dry run of the full conversion rewrote only the five zone-less tables and converted a value at the 2026-03-29 DST change unchanged.
- `.agents/skills/postgres-remediation/references/doctrine.md` (datetime types, generated-column handling, cursor precision, rounding on `timestamptz(0)`), `references/remediation.md`.
- Prior precision work: `tests/Integration/JobMonitorInstantPrecisionMigrationTest.php` and `JobMonitorService::extractSortValue`.

---

## Implementation Units

### U1. Project instant type

- **Goal:** ORM writes and reads of instants are exact and zone-independent.
- **Requirements:** R3, R5, R6; KTD1, KTD3.
- **Dependencies:** none.
- **Files:**
  - new type under `src/Shared/Infrastructure/Doctrine/Type/`
  - `src/Shared/Infrastructure/Doctrine/Type/CustomTypesRegistrar.php`, `config/packages/doctrine.yaml`
  - `src/Shared/Infrastructure/Doctrine/Platform/BaanderDriverMiddleware.php` (session time zone)
  - the six scheduler stores' `fromDsn` factories under `src/Scheduler/Infrastructure/Doctrine/` and their service wiring (middleware on their connections)
  - standalone type registrations in tests and fixtures (route them through the registrar)
  - new tests under `tests/Unit/Shared/Infrastructure/Doctrine/Type/` and a kernel-registration test
- **Approach:** the type converts every value to UTC before formatting `Y-m-d H:i:s.uP`, declares `TIMESTAMPTZ`, and parses PostgreSQL output (with or without fraction and offset) into UTC. One registrar list feeds both kernels.
- **Patterns to follow:** existing custom types in `src/Shared/Infrastructure/Doctrine/Type/` and their registrar entries.
- **Test scenarios:**
  - A `DateTimeImmutable` in `Asia/Kathmandu` with microseconds round-trips through a `timestamptz` column in a session set to `America/New_York` as the same instant with the same microseconds.
  - Reading returns a UTC object whose `format(DATE_ATOM)` ends in `+00:00`.
  - PostgreSQL text without a fraction, with a fraction, with `+00` and with `+05:45` all parse to the right instant.
  - Null round-trips as null.
  - The console kernel and the Swoole kernel both resolve `datetime_immutable` and `datetimetz_immutable` to the project class.
  - The registrar's list and `doctrine.yaml`'s list name the same types.
  - A new default connection and a scheduler store's connection both report `TimeZone` UTC.
  - A Messenger message sent to and fetched back from the failed transport keeps its timestamps.
- **Verification:** type and kernel tests pass; `doctrine:migrations:status` still reads `executed_at`.

### U2. Convert the columns

- **Goal:** every instant column is `timestamptz` without precision, with local data intact.
- **Requirements:** R1, R2, R6; KTD2, KTD6.
- **Dependencies:** U1 (the type's declaration must match the migrated columns).
- **Files:**
  - new migration under `migrations/`
  - `tests/Unit/Shared/Infrastructure/Doctrine/MigrationVersionComparatorTest.php` (append the version)
  - new migration test beside `tests/Integration/JobMonitorInstantPrecisionMigrationTest.php`
  - new catalog test beside `tests/Integration/DatabaseNamingConventionTest.php`
  - tests that assert `(0)` or zone-less types: `tests/Functional/Controller/NotificationSinceFilterTest.php`, and verify `tests/Integration/NotificationOwnershipPersistenceTest.php`, `LibraryAccessOwnershipPersistenceTest.php`, `JobMonitorInstantPrecisionMigrationTest.php` and `ReferentialIntegrityTest.php`, which run historical migrations down and up
- **Approach:** generate the column list from the catalog at implementation time and write it out explicitly in the migration; per KTD2. Delegate to the PostgreSQL specialist.
- **Execution note:** start with the migration test on a database seeded at the previous version, including a zone-less value at a DST change and a whole-second `timestamptz(0)` value.
- **Test scenarios:**
  - A zone-less value `2026-03-29 01:30:00` becomes `2026-03-29 01:30:00+00`.
  - A `timestamptz(0)` value keeps its instant.
  - After the migration, a fractional instant written to a formerly `(0)` column is stored with its microseconds.
  - Every table's row count is unchanged.
  - `down()` restores `(0)` and zone-less types, converting with UTC.
  - The catalog test fails on a column added as `timestamp` or `timestamptz(3)`, and passes on the migrated schema except `doctrine_migration_versions.executed_at`.
  - `DatabaseNamingConventionTest` (schema drift) passes.
- **Verification:** integration and functional suites pass on a fresh install and on a database migrated from the previous version.

### U3. Writes and comparisons that bypass the type

- **Goal:** every hand-written write or comparison of an instant is exact and zone-independent.
- **Requirements:** R3, R4; KTD3, KTD4.
- **Dependencies:** U1, U2.
- **Files:**
  - `src/Recommendation/Infrastructure/Swoole/RecommendationPoolWorker.php` (offset bind; session time zone on its PDO)
  - `src/Auth/Infrastructure/Repository/EmailVerificationTokenRepository.php`, `PasswordResetTokenRepository.php` (bind type)
  - `src/Shared/Domain/Event/Outbox/OutboxRepository.php`, `src/Shared/Infrastructure/Event/NotificationDeliveryRepository.php` (fraction in `created_at`, `next_attempt_at`, `dead_lettered_at`)
  - a guard test against whole-second and zone-less instant binds in `src/`
  - any site the audit finds (`::date`, zone-less `date_trunc`, `BETWEEN`, `<=` upper bounds on instants)
  - their tests
- **Approach:** per KTD4; replace zone-less strings with the instant type or a `Y-m-d H:i:s.uP` literal; express ranges half-open.
- **Test scenarios:**
  - A token created and looked up within the same second expires at its exact instant (no rounding to the second).
  - The recommendation job cleanup removes jobs older than its cutoff and keeps one created a microsecond later.
  - An outbox event appended and a delivery failure recorded store `created_at`, `next_attempt_at` and `dead_lettered_at` with microseconds.
  - The bind guard fails on a fixture that formats an instant as `Y-m-d H:i:s` or binds `Types::DATETIMETZ_IMMUTABLE`.
  - In a session set to a non-UTC zone, each fixed write stores the same instant as in a UTC session.
- **Verification:** affected unit and functional tests pass.

### U4. Cursors and API fields

- **Goal:** paging by time is exact, and API timestamp fields all use the documented format.
- **Requirements:** R4, R5; KTD5.
- **Dependencies:** U1, U2.
- **Files:**
  - `src/Catalog/Infrastructure/Doctrine/Repository/SongRepository.php` (the "added" cursor)
  - `src/Transcode/Application/DTO/TranscodeJobDto.php`, `TranscodeSessionDto.php` (`fromArray`)
  - `src/Recommendation/Infrastructure/Doctrine/Repository/RecommendationInsightsRepository.php`
  - `src/Recommendation/Interface/Console/RecommendationStatsCommand.php` (prints `last_generated_at`; fixed through the repository)
  - tests: `tests/Functional/Catalog/Infrastructure/Doctrine/Repository/SongCursorPaginationTest.php`, `tests/Functional/Catalog/Interface/Controller/SongListSortTest.php`, and the transcode and recommendation endpoint tests
- **Approach:** per KTD5, following `JobMonitorService::extractSortValue`.
- **Test scenarios:**
  - Three songs created within one second with different microseconds page in order, ascending and descending, with page size 1: no repeats, no gaps.
  - `GET /api/transcode/jobs/list` and `/api/transcode/sessions/list` return `createdAt` in `Y-m-d\TH:i:sP`.
  - The admin recommendation stats return `lastGeneratedAt` in the same format.
  - OpenAPI check passes unchanged.
- **Verification:** functional tests pass; OpenAPI and the generated web client are unchanged.

### U5. Docs and close-out

- **Goal:** the convention is written down where developers and agents look.
- **Requirements:** R7.
- **Dependencies:** U1 to U4.
- **Files:**
  - `.agents/skills/postgres-remediation/references/doctrine.md` (the override, the convention, schema comparison blind to time zone as well as precision, `executed_at`, partial-index rebuild on `ALTER TYPE`, installed Doctrine versions)
  - the developer guide's persistence or testing page that covers Doctrine types
  - `ROADMAP.md` (item 3 closed, quality-gate checkpoint, and an entry for bringing the dev database's migration history current)
- **Test expectation:** none -- documentation; prose-fix pass and `git diff --check`.
- **Verification:** the skill and guide describe the convention; ROADMAP item 3 lists nothing open.

---

## Verification Contract

| Gate | Command or check |
|---|---|
| Unit | container unit runner (`scripts/test-unit-container.sh`); focused host runs, never `tests/Unit/Shared` whole on the host |
| Functional and integration | `BAANDER_TEST_IMAGE=baander-ci-prewarm:local bash scripts/test-functional-container.sh tests/Functional` and `tests/Integration` |
| Migration | the new migration test; historical down/up tests; a database migrated from the previous version |
| Static | PHPStan full scan, Deptrac 0, `lint:container`, OpenAPI check in the CI image and on the host with a fresh cache |
| Runtime | the worker and web runtime drills (they exercise the Swoole kernel's type registration) |

## Definition of Done

- R1 to R7 are met.
- No instant column outside `doctrine_migration_versions.executed_at` is zone-less or has an explicit precision; no hand-written instant bind is zone-less or whole-second.
- Code from abandoned approaches is removed.
- Changes are committed on `plan/item3-timestamps`; nothing is pushed until the user asks.
