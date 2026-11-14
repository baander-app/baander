<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ArtistIdentityResolver;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;

/**
 * The production artist identity resolver over a disposable schema, shared by
 * ArtistImportIdentityRaceTest and the child process it runs (artist-import-identity.php) as a
 * second PostgreSQL session that can wait on the test's locks.
 */
final class ArtistImportIdentityRace
{
    /**
     * Copies a public table into the connection's current schema with its constraints and every
     * index except the PGroonga ones. With PGroonga indexes in their throwaway schemas, the races
     * were followed by another suite's PGroonga lookup in the same database failing with "object
     * isn't found"; without them it passes. The races need only the unique and B-tree indexes.
     */
    public static function copyTableWithoutPgroonga(Connection $connection, string $table): void
    {
        $schema = (string) $connection->fetchOne('SELECT current_schema()');
        $connection->executeStatement(sprintf('CREATE TABLE %s (LIKE public.%s INCLUDING ALL EXCLUDING INDEXES)', $table, $table));
        $definitions = $connection->fetchFirstColumn(
            "SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = :table AND indexdef NOT LIKE '% USING pgroonga %'",
            ['table' => $table],
        );
        foreach ($definitions as $definition) {
            $connection->executeStatement(str_replace(' ON public.' . $table . ' ', ' ON ' . $schema . '.' . $table . ' ', (string) $definition));
        }
    }

    public static function connect(string $schema, ?string $applicationName = null): Connection
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            throw new \LogicException('OUTBOX_TEST_DATABASE_URL must name a disposable PostgreSQL database.');
        }
        CustomTypesRegistrar::register();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new BaanderDriverMiddleware()]);
        $connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url), $configuration);
        $connection->executeStatement('SET search_path TO ' . $schema . ', public');
        if ($applicationName !== null) {
            $connection->executeQuery("SELECT set_config('application_name', :name, false)", ['name' => $applicationName])->free();
        }

        return $connection;
    }

    public static function entityManager(Connection $connection): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src'], isDevMode: true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $config->enableNativeLazyObjects(true);

        return new EntityManager($connection, $config);
    }

    public static function resolver(EntityManager $manager): ArtistIdentityResolver
    {
        return new ArtistIdentityResolver($manager);
    }

    /**
     * @param list<array{0: string, 1: list<string>}> $identities tagged name and MusicBrainz IDs
     *
     * @return list<ArtistIdentity>
     */
    public static function identities(array $identities): array
    {
        return array_map(static fn (array $identity): ArtistIdentity => new ArtistIdentity($identity[0], $identity[1]), $identities);
    }

    /**
     * Starts the child session: `resolve` resolves identities in one transaction and prints the
     * artist ID of each; `lock` takes import identity locks on keys in one transaction.
     *
     * @param 'resolve'|'lock' $mode
     * @param list<mixed>      $payload identities as for identities(), or lock keys
     *
     * @return array{process: resource, output: resource, application: string}
     */
    public static function start(string $schema, string $mode, array $payload): array
    {
        $application = 'artist_identity_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create the contender output.');
        }
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/artist-import-identity.php', $schema, $application, $mode, json_encode($payload, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new \RuntimeException('Cannot start the contender.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /** @param array{process: resource, output: resource, application: string} $child */
    public static function awaitAdvisoryLockWait(Connection $observer, array $child): void
    {
        $deadline = microtime(true) + 5;
        do {
            $observer->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            if ($observer->fetchOne(
                "SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock' AND wait_event = 'advisory'",
                [$child['application']],
            ) !== false) {
                return;
            }
            if (!proc_get_status($child['process'])['running']) {
                break;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        rewind($child['output']);
        throw new \RuntimeException('The contender did not wait on an advisory lock: ' . stream_get_contents($child['output']));
    }

    /**
     * @param array{process: resource, output: resource, application: string} $child
     *
     * @return array<string, mixed>
     */
    public static function finish(array $child, ?Connection $observer = null): array
    {
        $deadline = microtime(true) + 15;
        while (proc_get_status($child['process'])['running']) {
            if (microtime(true) > $deadline) {
                try {
                    $activity = $observer?->fetchAllAssociative(
                        'SELECT state, wait_event_type, wait_event, query, pg_blocking_pids(pid) AS blockers FROM pg_stat_activity WHERE application_name = ?',
                        [$child['application']],
                    );
                } finally {
                    proc_terminate($child['process'], 9);
                    rewind($child['output']);
                    $output = (string) stream_get_contents($child['output']);
                    fclose($child['output']);
                    proc_close($child['process']);
                }
                throw new \RuntimeException('The contender did not finish. Activity: ' . json_encode($activity, JSON_THROW_ON_ERROR) . ' Output: ' . $output);
            }
            usleep(1000);
        }
        rewind($child['output']);
        $result = (string) stream_get_contents($child['output']);
        fclose($child['output']);
        if (proc_close($child['process']) !== 0) {
            throw new \RuntimeException('The contender failed: ' . $result);
        }
        $decoded = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid contender output: ' . $result);
        }

        return $decoded;
    }
}
