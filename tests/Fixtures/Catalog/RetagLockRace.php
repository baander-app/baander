<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Infrastructure\Doctrine\Repository\SongRepository;
use App\Catalog\Infrastructure\SongService;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Runs a retag's locks, as the production song service takes them, in a child process
 * (retag-lock.php) with its own PostgreSQL session, so RetagLockOrderTest can see which locks the
 * retag holds while it waits on one the test holds.
 */
final class RetagLockRace
{
    public static function songs(Connection $connection): SongService
    {
        return new SongService(
            new SongRepository(ArtistImportIdentityRace::entityManager($connection), new CursorPaginator(), new CursorCodec(new JsonEncoder())),
            $connection,
        );
    }

    /**
     * Starts the child session, which takes the locks in one transaction and prints
     * {"locked": true} once it has committed.
     *
     * @param list<array{0: string, 1: list<string>}> $artists tagged name and MusicBrainz IDs of each artist
     * @param list<string>                            $genres  tagged genre names
     * @param list<string>                            $songIds
     *
     * @return array{process: resource, output: resource, application: string}
     */
    public static function start(string $schema, array $artists, array $genres, array $songIds): array
    {
        $application = 'retag_lock_' . (random_bytes(6) |> bin2hex(...));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create the retag output.');
        }
        $payload = json_encode(['artists' => $artists, 'genres' => $genres, 'songs' => $songIds], JSON_THROW_ON_ERROR);
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/retag-lock.php', $schema, $application, $payload],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new \RuntimeException('Cannot start the retag.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /**
     * Waits until the child waits on a lock, and returns its backend's process ID and the type of
     * the lock it waits on: "advisory" for an import identity lock, "transactionid" or "tuple" for
     * a row another session has locked.
     *
     * @param array{process: resource, output: resource, application: string} $child
     *
     * @return array{pid: int, waitsOn: string}
     */
    public static function awaitLockWait(Connection $observer, array $child): array
    {
        $deadline = microtime(true) + 5;
        do {
            $observer->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            $waiting = $observer->fetchAssociative(
                'SELECT activity.pid, waiting.locktype FROM pg_stat_activity activity JOIN pg_locks waiting ON waiting.pid = activity.pid AND NOT waiting.granted WHERE activity.application_name = ?',
                [$child['application']],
            );
            if ($waiting !== false) {
                return ['pid' => (int) $waiting['pid'], 'waitsOn' => (string) $waiting['locktype']];
            }
            if (!proc_get_status($child['process'])['running']) {
                break;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        rewind($child['output']);
        throw new \RuntimeException('The retag did not wait on a lock: ' . stream_get_contents($child['output']));
    }
}
