<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Application\Service\MovieFileIngest;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Service\VideoName\MovieNameParser;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\TvCatalogRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoRepository;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Library\Infrastructure\Filesystem\LibraryMediaFiles;
use App\Library\Infrastructure\Filesystem\MediaFileGuard;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;

final class MovieIdentityRace
{
    public static function ingest(EntityManager $manager): MovieFileIngest
    {
        $clock = new NativeClock();
        return new MovieFileIngest(
            new MovieRepository($manager),
            new TvCatalogRepository($manager),
            new VideoRepository($manager),
            new MovieNameParser(),
            new LibraryMediaFiles(
                new LibraryRepository($manager, $clock),
                new LibraryFileIndexRepository($manager),
                new MediaFileGuard(),
                $manager,
                $clock,
                new NullLogger(),
            ),
        );
    }

    /** @param list<DiscoveredFile> $files */
    public static function import(EntityManager $manager, Uuid $libraryId, array $files): void
    {
        $ingest = self::ingest($manager);
        $context = $ingest->prepareBatch($libraryId, $files);
        $videos = new VideoRepository($manager);
        foreach ($files as $file) {
            $video = $videos->findByHash($file->hash);
            if ($video === null) {
                $video = Video::create($file->absolutePath, $file->hash);
                $videos->save($video);
            }
            $ingest->ingestFile($libraryId, $file, $video, $context);
        }
        $ingest->flush();
    }

    /** @return array{process: resource, output: resource, application: string} */
    public static function start(string $schema, Uuid $libraryId, DiscoveredFile $file): array
    {
        $application = 'movie_identity_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create the movie contender output.');
        }
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/movie-identity-race.php',
                $schema,
                $application,
                $libraryId->toString(),
                json_encode(get_object_vars($file), JSON_THROW_ON_ERROR),
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new \RuntimeException('Cannot start the movie contender.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /** @param array{process: resource, output: resource, application: string} $child */
    public static function awaitLockWait(Connection $observer, array $child): void
    {
        ArtistImportIdentityRace::awaitAdvisoryLockWait($observer, $child);
    }

    /** @param array{process: resource, output: resource, application: string} $child */
    public static function finish(array $child): string
    {
        $status = proc_close($child['process']);
        rewind($child['output']);
        $output = stream_get_contents($child['output']);
        fclose($child['output']);
        if ($status !== 0 || $output === false) {
            throw new \RuntimeException('Movie contender failed: ' . $output);
        }

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }
}
