<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Infrastructure\Doctrine\Repository\GenreRepository;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;

/**
 * The import's genre step over a disposable schema, shared by GenreImportRaceTest and the child
 * process it runs (genre-import.php) as a second PostgreSQL session. Connections, entity
 * managers and the child's result come from {@see ArtistImportIdentityRace}.
 */
final class GenreImportRace
{
    /**
     * Finds or creates the genres as an import batch does, inside the caller's transaction, then
     * flushes as the batch does once it has written its songs. A failed flush closes the entity
     * manager, and the closed manager refuses that second flush, so a batch that lost a race fails.
     *
     * @param list<string> $names
     *
     * @return array<string, string> the genre ID of each name
     */
    public static function import(EntityManager $manager, array $names): array
    {
        $ids = new GenreRepository($manager)->findOrCreateForImport($names);
        $manager->flush();

        return array_map(static fn (Uuid $id): string => $id->toString(), $ids);
    }

    /**
     * Starts the child session, which imports the names in one transaction and prints
     * {"genres": [genre ID of each name]}.
     *
     * @param list<string> $names
     *
     * @return array{process: resource, output: resource, application: string}
     */
    public static function start(string $schema, array $names): array
    {
        $application = 'genre_import_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create the contender output.');
        }
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/genre-import.php', $schema, $application, json_encode($names, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new \RuntimeException('Cannot start the contender.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /**
     * Waits until the child waits on any lock: an advisory import lock, or the unique index entry
     * of a genre the test session inserted and has not committed.
     *
     * @param array{process: resource, output: resource, application: string} $child
     */
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
        rewind($child['output']);
        throw new \RuntimeException('The contender did not wait on a lock: ' . stream_get_contents($child['output']));
    }
}
