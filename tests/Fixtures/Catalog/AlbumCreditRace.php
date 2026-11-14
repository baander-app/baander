<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Application\Service\CoverImageDiscarder;
use App\Catalog\Infrastructure\AlbumService;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ArtistIdentityResolver;
use App\Catalog\Infrastructure\Doctrine\Repository\AlbumRepository;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManager;

/**
 * The album credit decision over a disposable schema, shared by AlbumCreditRaceTest and the child
 * process it runs (album-credit.php) as a second PostgreSQL session. Connections, entity managers
 * and the child's result come from {@see ArtistImportIdentityRace}.
 */
final class AlbumCreditRace
{
    /**
     * Decides the album's artist credit inside the caller's transaction, so the caller chooses
     * when the album's row lock is released.
     *
     * @param array{0: string, 1: list<string>, 2: string}|null $albumArtist the tagged album artist's name, MusicBrainz IDs and credited name
     */
    public static function decide(EntityManager $manager, string $albumId, ?array $albumArtist): void
    {
        new AlbumService(
            new AlbumRepository($manager),
            // The credit decision never discards a cover image.
            new \ReflectionClass(CoverImageDiscarder::class)->newInstanceWithoutConstructor(),
            new PassThroughTransaction(),
            new ArtistIdentityResolver($manager),
        )->creditArtistIfUncredited(
            Uuid::fromString($albumId),
            $albumArtist === null ? null : new ArtistIdentity($albumArtist[0], $albumArtist[1]),
            $albumArtist[2] ?? null,
        );
    }

    /**
     * Starts the child session, which decides the album's credit in one transaction and prints
     * {"decided": true}.
     *
     * @param array{0: string, 1: list<string>, 2: string}|null $albumArtist as for decide()
     *
     * @return array{process: resource, output: resource, application: string}
     */
    public static function start(string $schema, string $albumId, ?array $albumArtist): array
    {
        $application = 'album_credit_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create the contender output.');
        }
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/album-credit.php', $schema, $application, json_encode([$albumId, $albumArtist], JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new \RuntimeException('Cannot start the contender.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }
}
