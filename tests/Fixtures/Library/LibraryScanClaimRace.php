<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Library;

use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;

/**
 * The production library repository over a disposable schema, shared by LibraryScanClaimTest
 * and the child process it runs (library-scan-claim.php) as a second PostgreSQL session.
 */
final class LibraryScanClaimRace
{
    public static function connect(string $schema, ?string $applicationName = null): Connection
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            throw new \LogicException('OUTBOX_TEST_DATABASE_URL must name a disposable PostgreSQL database.');
        }
        $connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $connection->executeStatement('SET search_path TO ' . $schema . ', public');
        if ($applicationName !== null) {
            $connection->executeQuery("SELECT set_config('application_name', :name, false)", ['name' => $applicationName])->free();
        }

        return $connection;
    }

    public static function entityManager(Connection $connection): EntityManager
    {
        CustomTypesRegistrar::register();
        $config = ORMSetup::createAttributeMetadataConfig([
            dirname(__DIR__, 3) . '/src/Library/Infrastructure/Doctrine/Entity',
        ], isDevMode: true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $config->enableNativeLazyObjects(true);

        return new EntityManager($connection, $config);
    }

    public static function repository(EntityManager $manager): LibraryRepository
    {
        return new LibraryRepository($manager);
    }
}
