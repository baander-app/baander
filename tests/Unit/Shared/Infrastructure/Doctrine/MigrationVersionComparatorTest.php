<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\MigrationVersionComparator;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\FilesystemMigrationsRepository;
use Doctrine\Migrations\Finder\GlobFinder;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Version\MigrationFactory;
use Doctrine\Migrations\Version\SortedMigrationPlanCalculator;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;

final class MigrationVersionComparatorTest extends TestCase
{
    /** @var list<string> */
    private const ORDER = [
        'DoctrineMigrations\\Version001_InitialSchema',
        'DoctrineMigrations\\Version20260525190000',
        'DoctrineMigrations\\Version320260606CreateDiscoveryFavorites',
        'DoctrineMigrations\\Version420260613CreateUserThemeMoods',
        'DoctrineMigrations\\Version520260619CreateDomainEventOutbox',
        'DoctrineMigrations\\Version620260619CreateMissingEntityTables',
        'DoctrineMigrations\\Version720260703AddRecommendationsUniqueIndex',
        'DoctrineMigrations\\Version_80000000_20260715AddOutboxRetryColumns',
        'DoctrineMigrations\\Version920260715CreateEmailVerificationAndPkceColumns',
        'DoctrineMigrations\\Version20260715172602',
        'DoctrineMigrations\\Version20260716235039',
        'DoctrineMigrations\\Version20261001170000',
        'DoctrineMigrations\\Version20261001171000',
        'DoctrineMigrations\\Version20261002120000',
        'DoctrineMigrations\\Version20261002210000',
        'DoctrineMigrations\\Version20261002220000',
        'DoctrineMigrations\\Version20261002230000',
        'DoctrineMigrations\\Version20261003010000',
        'DoctrineMigrations\\Version20261003020000',
        'DoctrineMigrations\\Version20261004010000',
        'DoctrineMigrations\\Version20261006170000',
        'DoctrineMigrations\\Version20261006180000',
        'DoctrineMigrations\\Version20261006181000',
        'DoctrineMigrations\\Version20261006190000',
        'DoctrineMigrations\\Version20261006200000',
        'DoctrineMigrations\\Version20261006201000',
        'DoctrineMigrations\\Version20261006210000',
        'DoctrineMigrations\\Version20261006230000',
        'DoctrineMigrations\\Version20261006240000',
        'DoctrineMigrations\\Version20261006250000',
        'DoctrineMigrations\\Version20261006260000',
        'DoctrineMigrations\\Version20261006270000',
        'DoctrineMigrations\\Version20261006280000',
        'DoctrineMigrations\\Version20261006290000',
        'DoctrineMigrations\\Version20261006300000',
        'DoctrineMigrations\\Version20261006310000',
        'DoctrineMigrations\\Version20261006320000',
        'DoctrineMigrations\\Version20261006330000',
        'DoctrineMigrations\\Version20261006340000',
        'DoctrineMigrations\\Version20261006360000',
    ];

    public function testFreshPlanOrdersAllActualMigrationClassesByDependencies(): void
    {
        $plan = $this->calculator()->getPlanUntilVersion(new Version(self::ORDER[array_key_last(self::ORDER)]));

        self::assertSame(self::ORDER, array_map(static fn ($item): string => (string) $item->getVersion(), $plan->getItems()));
    }

    public function testAppliedLegacyIdentitiesAreSkippedWithoutRenaming(): void
    {
        $applied = array_slice(self::ORDER, 0, 11);
        $plan = $this->calculator($applied)->getPlanUntilVersion(new Version(self::ORDER[array_key_last(self::ORDER)]));

        self::assertSame(array_slice(self::ORDER, 11), array_map(static fn ($item): string => (string) $item->getVersion(), $plan->getItems()));
    }

    public function testFullyAppliedPlanIsEmpty(): void
    {
        $plan = $this->calculator(self::ORDER)->getPlanUntilVersion(new Version(self::ORDER[array_key_last(self::ORDER)]));

        self::assertCount(0, $plan->getItems());
    }

    public function testLatestAvailableMigrationIsLatestTimestamp(): void
    {
        self::assertSame(self::ORDER[array_key_last(self::ORDER)], (string) $this->calculator()->getMigrations()->getLast()->getVersion());
    }

    public function testFutureTimestampsAndUnknownFormatsHaveDeterministicOrder(): void
    {
        $comparator = new MigrationVersionComparator();
        $versions = [
            new Version('OtherMigrations\\Version20250101000000'),
            new Version('DoctrineMigrations\\VersionZCustom'),
            new Version('DoctrineMigrations\\Version20270101120000'),
            new Version(self::ORDER[array_key_last(self::ORDER)]),
        ];
        usort($versions, $comparator->compare(...));

        self::assertSame([
            self::ORDER[array_key_last(self::ORDER)],
            'DoctrineMigrations\\Version20270101120000',
            'DoctrineMigrations\\VersionZCustom',
            'OtherMigrations\\Version20250101000000',
        ], array_map(static fn (Version $version): string => (string) $version, $versions));
        self::assertSame(0, $comparator->compare($versions[0], $versions[0]));
    }

    public function testRollbackPlanReversesDependencyOrder(): void
    {
        $plan = $this->calculator(self::ORDER)->getPlanUntilVersion(new Version(self::ORDER[10]));

        self::assertSame(array_reverse(array_slice(self::ORDER, 11)), array_map(static fn ($item): string => (string) $item->getVersion(), $plan->getItems()));
    }

    /** @param list<string> $applied */
    private function calculator(array $applied = []): SortedMigrationPlanCalculator
    {
        $factory = $this->createStub(MigrationFactory::class);
        $factory->method('createVersion')->willReturn($this->createStub(AbstractMigration::class));
        $repository = new FilesystemMigrationsRepository(
            [],
            ['DoctrineMigrations' => dirname(__DIR__, 5) . '/migrations'],
            new GlobFinder(),
            $factory,
        );
        $metadata = $this->createStub(MetadataStorage::class);
        $metadata->method('getExecutedMigrations')->willReturn(new ExecutedMigrationsList(
            array_map(static fn (string $version): ExecutedMigration => new ExecutedMigration(new Version($version)), $applied),
        ));

        return new SortedMigrationPlanCalculator($repository, $metadata, new MigrationVersionComparator());
    }
}
