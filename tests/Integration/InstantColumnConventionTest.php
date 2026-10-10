<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Every column that records an instant is timestamptz at its native microsecond precision.
 *
 * Schema comparison cannot check this: introspection renders timestamp and timestamptz columns
 * of any precision with the same declaration, so the test reads the catalog. pg_attribute's
 * typmod tells an explicit precision apart, which information_schema's datetime_precision does
 * not (it reports 6 for both timestamptz and timestamptz(6)).
 */
final class InstantColumnConventionTest extends TestCase
{
    use OwnershipPersistenceHarness;

    public function testEveryInstantColumnIsTimestamptzWithoutExplicitPrecision(): void
    {
        self::assertSame([], $this->violations());
    }

    public function testAZoneLessOrFixedPrecisionColumnIsReported(): void
    {
        $this->manager->getConnection()->executeStatement(
            'ALTER TABLE webhooks ADD COLUMN convention_probe_local timestamp, ADD COLUMN convention_probe_millis timestamptz(3)',
        );

        self::assertSame([
            'webhooks.convention_probe_local: timestamp without time zone',
            'webhooks.convention_probe_millis: timestamp(3) with time zone',
        ], $this->violations());
    }

    /** @return list<string> */
    private function violations(): array
    {
        // Doctrine Migrations owns its metadata table and parses executed_at without a zone.
        return array_map(strval(...), $this->manager->getConnection()->fetchFirstColumn(<<<'SQL'
            SELECT c.relname || '.' || a.attname || ': ' || format_type(a.atttypid, a.atttypmod)
              FROM pg_attribute a
              JOIN pg_class c ON c.oid = a.attrelid
             WHERE c.relnamespace = current_schema()::regnamespace AND c.relkind IN ('r', 'p')
               AND a.attnum > 0 AND NOT a.attisdropped
               AND (a.atttypid = 'timestamp'::regtype OR (a.atttypid = 'timestamptz'::regtype AND a.atttypmod <> -1))
               AND (c.relname, a.attname) <> ('doctrine_migration_versions', 'executed_at')
             ORDER BY 1
            SQL));
    }
}
