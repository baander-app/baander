<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Activity\Infrastructure\Doctrine\ArtistActivityMerge;
use App\Catalog\Infrastructure\ArtistMergeService;
use App\Favorites\Infrastructure\Doctrine\ArtistFavoriteMerge;
use App\Recommendation\Infrastructure\Doctrine\ArtistRecommendationMerge;
use Doctrine\ORM\EntityManager;

/**
 * The production artist merge over a disposable schema, shared by ArtistMergeRaceTest and the
 * child process it runs (artist-merge.php) as a second PostgreSQL session. Connections, entity
 * managers and the child's result come from {@see ArtistImportIdentityRace}.
 */
final class ArtistMergeRace
{
    public static function merges(EntityManager $manager): ArtistMergeService
    {
        return new ArtistMergeService(
            $manager,
            new ArtistFavoriteMerge($manager),
            new ArtistActivityMerge($manager),
            new ArtistRecommendationMerge($manager),
        );
    }

    /**
     * Starts the child session. `credit` credits the artist on the song in one transaction and
     * prints {"credited": true}, or {"sqlstate": "..."} when the insert fails; `lock` locks the
     * artist through the merge port in one transaction and prints what lock() returned.
     *
     * @param 'credit'|'lock' $mode
     *
     * @return array{process: resource, output: resource, application: string}
     */
    public static function start(string $schema, string $mode, string $artistId, ?string $songId = null): array
    {
        $application = 'artist_merge_' . (random_bytes(6) |> bin2hex(...));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create the contender output.');
        }
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/artist-merge.php', $schema, $application, $mode, $artistId, $songId ?? ''],
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
