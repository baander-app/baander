<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Catalog\Infrastructure\Doctrine\Repository\AlbumMatchStateRepository;
use Doctrine\DBAL\Connection;

/**
 * The production album match state statements over a disposable schema, shared by
 * AlbumMatchClaimRaceTest and the child process it runs (album-match-claim.php) as a second
 * PostgreSQL session that can wait on a row the test session changed. Connections and the child's
 * result come from {@see ArtistImportIdentityRace}.
 */
final class AlbumMatchClaimRace
{
    public static function states(Connection $connection): AlbumMatchStateRepository
    {
        return new AlbumMatchStateRepository($connection);
    }

    /**
     * Starts the child session. `matched` ends a claim on a matched album and prints {"left": the
     * state it left the album in, or null}; `request` asks for the album's match and prints
     * {"dueSooner": whether the request brought the due time forward}.
     *
     * @param 'matched'|'request'   $step
     * @param array<string, string> $payload `album` and `claim` for a matched album; `album`, `source` and `dueAt` for a request
     *
     * @return array{process: resource, output: resource, application: string}
     */
    public static function start(string $schema, string $step, array $payload): array
    {
        $application = 'album_match_claim_' . (random_bytes(6) |> bin2hex(...));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create the contender output.');
        }
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/album-match-claim.php', $schema, $application, $step, json_encode($payload, JSON_THROW_ON_ERROR)],
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
     * Returns once the child waits for the transaction that changed the album's row and has not
     * committed.
     *
     * @param array{process: resource, output: resource, application: string} $child
     */
    public static function awaitRowWait(Connection $observer, array $child): void
    {
        $deadline = microtime(true) + 5;
        do {
            $observer->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            if ($observer->fetchOne(
                "SELECT 1 FROM pg_stat_activity activity JOIN pg_locks waiting ON waiting.pid = activity.pid AND NOT waiting.granted WHERE activity.application_name = ? AND waiting.locktype = 'transactionid'",
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
        throw new \RuntimeException('The contender did not wait on the album\'s row: ' . stream_get_contents($child['output']));
    }

    /** @return array{status: string, source: string, dueAt: string}|null the state as the child prints it */
    public static function described(?AlbumMatchState $state): ?array
    {
        return $state === null ? null : [
            'status' => $state->status->value,
            'source' => $state->source->value,
            'dueAt' => $state->dueAt->format('Y-m-d H:i:s.uP'),
        ];
    }
}
