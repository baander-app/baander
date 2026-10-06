<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261006240000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SidebarConfigIndexPersistenceTest extends TestCase
{
    use OwnershipPersistenceHarness;

    private const UNIQUE_INDEX = 'uniq_user_sidebar_configs_user_id_media_type';

    public function testSchemaComparisonIsCleanForSidebarConfigs(): void
    {
        $this->assertSchemaComparisonIsClean(['user_sidebar_configs']);
    }

    public function testTheUniqueMediaTypeIndexAloneCoversOwnerLookups(): void
    {
        $connection = $this->manager->getConnection();
        $indexes = static fn (): array => $connection->fetchAllKeyValue(
            'SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = \'user_sidebar_configs\' ORDER BY indexname',
        );

        self::assertSame([
            self::UNIQUE_INDEX => 'CREATE UNIQUE INDEX ' . self::UNIQUE_INDEX . ' ON public.user_sidebar_configs USING btree (user_id, media_type)',
            'user_sidebar_configs_pkey' => 'CREATE UNIQUE INDEX user_sidebar_configs_pkey ON public.user_sidebar_configs USING btree (id)',
        ], $indexes());

        // The owner-only predicate of the users foreign-key cascade uses the leading column.
        $connection->executeStatement('SET LOCAL enable_seqscan = off');
        $plan = implode("\n", $connection->fetchFirstColumn(
            'EXPLAIN (COSTS OFF) SELECT 1 FROM user_sidebar_configs WHERE user_id = :owner',
            ['owner' => '00000000-0000-4000-8000-000000000000'],
        ));
        self::assertStringContainsString(self::UNIQUE_INDEX, $plan);

        require_once dirname(__DIR__, 2) . '/migrations/Version20261006240000.php';
        foreach (['down', 'up'] as $direction) {
            $migration = new Version20261006240000($connection, new NullLogger());
            $migration->{$direction}(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
            self::assertSame($direction === 'down', array_key_exists('idx_user_sidebar_configs_user_id', $indexes()), $direction);
            self::assertArrayHasKey(self::UNIQUE_INDEX, $indexes(), $direction);
        }
    }
}
