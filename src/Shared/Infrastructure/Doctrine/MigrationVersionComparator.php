<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Version;

/**
 * Orders historical class identities without renaming applied migrations.
 * New migrations use VersionYYYYMMDDHHMMSS; unfamiliar formats sort last
 * alphabetically and require an explicit ordering decision before deployment.
 */
final class MigrationVersionComparator implements Comparator
{
    private const LEGACY_ORDER = [
        'DoctrineMigrations\\Version001_InitialSchema' => '00000000000000',
        'DoctrineMigrations\\Version320260606CreateDiscoveryFavorites' => '20260606000000',
        'DoctrineMigrations\\Version420260613CreateUserThemeMoods' => '20260613000000',
        'DoctrineMigrations\\Version520260619CreateDomainEventOutbox' => '20260619000000',
        'DoctrineMigrations\\Version620260619CreateMissingEntityTables' => '20260619000001',
        'DoctrineMigrations\\Version720260703AddRecommendationsUniqueIndex' => '20260703000000',
        'DoctrineMigrations\\Version_80000000_20260715AddOutboxRetryColumns' => '20260715000000',
        'DoctrineMigrations\\Version920260715CreateEmailVerificationAndPkceColumns' => '20260715000001',
    ];

    public function compare(Version $a, Version $b): int
    {
        $left = (string) $a;
        $right = (string) $b;

        return strcmp($this->orderingKey($left), $this->orderingKey($right)) ?: strcmp($left, $right);
    }

    private function orderingKey(string $version): string
    {
        if (isset(self::LEGACY_ORDER[$version])) {
            return self::LEGACY_ORDER[$version];
        }

        if (preg_match('/^DoctrineMigrations\\\\Version(\d{14})$/D', $version, $matches) === 1) {
            return $matches[1];
        }

        return '~' . $version;
    }
}
