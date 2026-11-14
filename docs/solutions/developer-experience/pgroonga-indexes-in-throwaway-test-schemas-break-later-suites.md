---
title: PGroonga indexes copied into throwaway test schemas can break PGroonga lookups in later suites
date: 2026-11-14
category: developer-experience
module: Integration test fixtures on the shared test database (race tests with per-test schemas)
problem_type: test_failure
component: testing_framework
related_components: [database]
severity: medium
symptoms:
  - "An unrelated later suite fails with SQLSTATE[22023]: Invalid parameter value: 7 ERROR:  pgroonga: PGrnLookupWithSize: object isn't found: <Sources50845>"
  - "The failing suite passes when run alone and fails only after a suite that created and dropped its own schemas"
root_cause: test_isolation
resolution_type: test_fix
framework_version: pgroonga 4.0.5 on postgresql 18.4
tags: [pgroonga, groonga, test-isolation, throwaway-schema, drop-schema, integration-tests, race-tests]
---

# PGroonga indexes copied into throwaway test schemas can break PGroonga lookups in later suites

## Problem

The import race tests each create a random schema, copy the tables they race on into it, and drop the schema with `CASCADE` in `tearDown`. The first version copied tables with `CREATE TABLE ... (LIKE public.<table> INCLUDING ALL)`, which also copies the PGroonga full-text index on `artists.name` and `genres.name`. After those tests ran, an unrelated suite in the same test database failed on a PGroonga lookup.

## Symptoms

- `App\Tests\Integration\LibraryDirectoryPublicationTest::testHashReversionKeepsTheOriginalQueuedDiscoveryEligibleAndIdempotent` failed with `Doctrine\DBAL\Exception\DriverException: ... SQLSTATE[22023]: Invalid parameter value: 7 ERROR:  pgroonga: PGrnLookupWithSize: object isn't found: <Sources50845>`.
- The publication suite passed on its own and failed only in a run that included the new race tests before it. Nothing in the failing test touched the race tests' tables.

## What Didn't Work

- **Reading the failure as a defect in the failing suite.** The error comes from a PGroonga lookup in `LibraryDirectoryPublicationTest`, but that suite and the code it covers had not changed. The run order was the only difference, which pointed at the newly added suites that create and drop schemas.
- **Checking the PostgreSQL extension inventory.** `.agents/skills/postgres-remediation/references/extensions.md` records PGroonga's planner behaviour and says never to drop PGroonga indexes to silence schema diffs, but it says nothing about indexes created and dropped with a temporary schema.

## Solution

Copy the table into the throwaway schema without its PGroonga indexes, and recreate every other index of the public table. `ArtistImportIdentityRace::copyTableWithoutPgroonga` (`tests/Fixtures/Catalog/ArtistImportIdentityRace.php:33`) does this:

```php
$schema = (string) $connection->fetchOne('SELECT current_schema()');
$connection->executeStatement(sprintf(
    'CREATE TABLE %s (LIKE public.%s INCLUDING ALL EXCLUDING INDEXES)', $table, $table,
));
$definitions = $connection->fetchFirstColumn(
    "SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = :table AND indexdef NOT LIKE '% USING pgroonga %'",
    ['table' => $table],
);
foreach ($definitions as $definition) {
    $connection->executeStatement(str_replace(
        ' ON public.' . $table . ' ', ' ON ' . $schema . '.' . $table . ' ', (string) $definition,
    ));
}
```

`ArtistImportIdentityRaceTest`, `GenreImportRaceTest` and `AlbumCreditRaceTest` all use it. With the change, `LibraryDirectoryPublicationTest` passed 41 of 41 in a run after `ArtistImportIdentityRaceTest`.

## Why This Works

`EXCLUDING INDEXES` stops `LIKE ... INCLUDING ALL` from copying any index, and the helper reads `pg_indexes` for the public table and recreates every index except those `USING pgroonga`. The throwaway schema then holds no PGroonga index, so dropping it never involves PGroonga. The race tests need the unique keys (`uniq_artists_mbid`, `uniq_genres_slug`, `uniq_genres_name_lower`) and the B-tree name indexes, not full-text search.

What exactly breaks the later lookup was not isolated; the fix rests on the observed before and after. PGroonga keeps each index's data as Groonga objects in a per-database Groonga store outside PostgreSQL's catalog (`Sources<number>` names one), so a lookup can fail on a missing Groonga object while the PostgreSQL catalog looks healthy. Dropping a PGroonga index with its schema is not enough on its own to cause that failure: `tests/Integration/PgroongaSearchCompatibilityTest.php` creates a PGroonga index on `genres` in its own schema and drops the schema with `CASCADE` from one session, with no such failure on record. The race tests differ in that two sessions use the copied tables at once (the test session and a child PHP process) and several tests create and drop schemas in quick succession. Which of these matters is open.

## Prevention

- In a test that creates tables in its own schema on the shared test database (`OUTBOX_TEST_DATABASE_URL`) and does not need full-text search, copy them with `ArtistImportIdentityRace::copyTableWithoutPgroonga`, or with `EXCLUDING INDEXES` plus the indexes the test needs. The tables with a PGroonga index are `artists`, `genres`, `albums`, `songs` and `movies` (find them with `SELECT tablename, indexname FROM pg_indexes WHERE indexdef LIKE '% USING pgroonga %'`). The failure appeared only with the race tests' shape; single-session fixtures that copy such tables with bare `LIKE ... INCLUDING ALL`, such as `tests/Fixtures/Media/image-read-scope-plan.php`, have no failure on record, so treat them as a risk to remove when touched, not a known defect.
- A test that needs PGroonga itself in a throwaway schema should keep the shape `tests/Integration/PgroongaSearchCompatibilityTest.php` uses (one session from setup to drop) and run the suites after it in the same database before relying on a different shape.
- When a suite fails with `pgroonga: ... object isn't found` and passes alone, look at the suites that ran earlier in the same database for created and dropped PGroonga indexes before debugging the failing suite.
- The postgres specialist profile (`.agents/agents/postgres.md`) refreshes the PostgreSQL extension inventory during its assigned work, and `AGENTS.md` keeps one writer for it. The behaviour above is a candidate for that inventory once someone isolates the mechanism on the disposable `baander-database` image.

## Related Issues

- `docs/solutions/architecture-patterns/concurrent-imports-find-or-create-identities-under-sorted-advisory-locks.md`: the race tests whose schemas triggered this, and how they run a second session.
- `.agents/skills/postgres-remediation/references/extensions.md`: the verified PGroonga 4.0.5 inventory.
