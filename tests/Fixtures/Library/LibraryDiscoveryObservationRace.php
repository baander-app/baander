<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Library;

use App\Library\Application\MovieScanner;
use App\Library\Application\MusicScanner;
use App\Library\Application\Port\DirectoryScannerPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Library\Infrastructure\Scanner\MediaFile;
use App\Shared\Application\Port\JobCancellationCheckpointInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;

/** Disposable second sessions for observation-lock tests; no application database is reset. */
final class LibraryDiscoveryObservationRace
{
    /** @param list<string> $paths */
    public static function scanner(Connection $connection, Library $library, array $paths, TransactionPortInterface $transaction): MusicScanner|MovieScanner
    {
        $directory = new class($paths) implements DirectoryScannerPortInterface {
            /** @param list<string> $paths */
            public function __construct(private array $paths) {}
            public function scan(LibraryPath $path): array
            {
                return array_map(static fn (string $file): MediaFile => new MediaFile($file, basename($file), pathinfo($file, PATHINFO_EXTENSION), (int) filesize($file), (int) filemtime($file)), $this->paths);
            }
        };
        $cancellation = new class implements JobCancellationCheckpointInterface {
            public function check(): void {}
        };
        $index = new LibraryFileIndexRepository(LibraryScanClaimRace::entityManager($connection));
        $arguments = [$directory, $index, new NullLogger(), $cancellation, $transaction];
        return $library->getType() === LibraryType::Music ? new MusicScanner(...$arguments) : new MovieScanner(...$arguments);
    }

    /** @param array<string, mixed> $payload
     * @return array{process: resource, output: resource, application: string, payload: string}
     */
    public static function start(string $schema, string $kind, array $payload): array
    {
        $application = 'discovery_observation_' . bin2hex(random_bytes(6));
        $file = tempnam(sys_get_temp_dir(), 'baander-observation-');
        $output = tmpfile();
        if ($file === false || $output === false) {
            throw new \RuntimeException('Cannot create observation child files.');
        }
        file_put_contents($file, json_encode($payload, JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, __DIR__ . '/library-discovery-observation.php', $schema, $application, $kind, $file], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes);
        if (!is_resource($process)) {
            fclose($output);
            unlink($file);
            throw new \RuntimeException('Cannot start observation contender.');
        }
        return ['process' => $process, 'output' => $output, 'application' => $application, 'payload' => $file];
    }

    /** @param array{process: resource, output: resource, application: string, payload: string} $child */
    public static function awaitLockWait(Connection $observer, array $child, int $blockerPid): void
    {
        $deadline = microtime(true) + 15;
        do {
            $observer->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            if ($observer->fetchOne("SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock' AND ? = ANY(pg_blocking_pids(pid))", [$child['application'], $blockerPid]) !== false) {
                return;
            }
            if (!proc_get_status($child['process'])['running']) {
                break;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Contender did not enter the expected PostgreSQL lock wait.');
    }

    /** @param array{process: resource, output: resource, application: string, payload: string} $child
     * @return array<string, mixed>
     */
    public static function finish(array $child): array
    {
        try {
            return LibraryScanClaimRace::finish($child);
        } finally {
            unlink($child['payload']);
        }
    }
}
