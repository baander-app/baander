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
use Symfony\Component\Clock\NativeClock;

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
        $connection->executeStatement("SET TIME ZONE 'UTC'");
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
        return new LibraryRepository($manager, new NativeClock());
    }
    /** @return array{process: resource, output: resource, application: string} */
    public static function start(string $schema, string $libraryId, string $kind = 'scan'): array
    {
        $application = 'library_claim_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create claim race output.');
        }
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/library-scan-claim.php', $schema, $application, $libraryId, $kind],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new \RuntimeException('Cannot start claim contender.');
        }
        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /** @param array{process: resource, output: resource, application: string} $child */
    public static function awaitLockWait(Connection $observer, array $child): void
    {
        $deadline = microtime(true) + 5;
        do {
            $observer->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            if ($observer->fetchOne(
                "SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'",
                [$child['application']],
            ) !== false) {
                return;
            }
            if (!proc_get_status($child['process'])['running']) {
                break;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('The contender did not wait for the scan transaction.');
    }

    /**
     * @param array{process: resource, output: resource, application: string} $child
     * @return array<string, mixed>
     */
    public static function finish(array $child): array
    {
        $deadline = microtime(true) + 10;
        while (proc_get_status($child['process'])['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($child['process'], 9);
                throw new \RuntimeException('Claim contender did not finish.');
            }
            usleep(1000);
        }
        rewind($child['output']);
        $result = (string) stream_get_contents($child['output']);
        fclose($child['output']);
        if (proc_close($child['process']) !== 0) {
            throw new \RuntimeException('Claim contender failed: ' . $result);
        }
        $decoded = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid claim contender output.');
        }
        return $decoded;
    }

}
