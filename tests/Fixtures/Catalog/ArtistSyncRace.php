<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Application\Command\MusicBrainzMatch\ApplyMatchCommand;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchApplyResult;
use App\Catalog\Application\MessageHandler\SyncArtistHandler;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Service\ArtistSyncQueue;
use App\Catalog\Application\Service\MusicBrainzMatch\ArtistMatchWriter;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\FakeMusicBrainzLookup;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The production artist sync over a disposable schema, shared by ArtistSyncRaceTest and the child
 * process it runs (artist-sync.php) as a second PostgreSQL session, over the catalog services of
 * {@see MatchApplyRace}. The marks the sync keeps are in memory: only the catalog races here.
 */
final class ArtistSyncRace
{
    /** Stores an artist with a MusicBrainz ID and no details; returns its ID. */
    public static function insertArtist(Connection $connection, string $name, string $musicbrainzId): string
    {
        $id = (new Uuid())->toString();
        $connection->executeStatement(
            'INSERT INTO artists (id, public_id, name, mbid, created_at, updated_at) VALUES (?, ?, ?, ?, clock_timestamp(), clock_timestamp())',
            [$id, (new PublicId())->toString(), $name, $musicbrainzId],
        );

        return $id;
    }

    /** MusicBrainz's record of an artist, with the details a sync fills. */
    public static function answer(string $musicbrainzId, string $name, string $country, string $type = 'Person', string $sortName = 'Malone, Post'): MusicBrainzArtist
    {
        return new MusicBrainzArtist($musicbrainzId, $name, 0, $sortName, $type, $country, null, null, null, false);
    }

    /** The sync handler over the manager. */
    public static function handler(EntityManager $manager, FakeMusicBrainzLookup $lookup): SyncArtistHandler
    {
        $services = MatchApplyRace::services($manager, null);
        $marks = new InMemoryQueuedArtistSyncs();
        $bus = new class () implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \LogicException('The sync in a race queues nothing.');
            }
        };

        return new SyncArtistHandler(
            $services['artists'],
            $services['merges'],
            $lookup,
            $services['comparisons'],
            new ArtistMatchWriter($services['artists']),
            $marks,
            new ArtistSyncQueue($marks, $bus),
            $services['transaction'],
            new NullLogger(),
        );
    }

    /**
     * Applies an artist match by hand through the production apply, as an admin does: every row the
     * comparison ticks. $beforeCommit runs inside the apply's transaction once it wrote everything,
     * while it holds the artist's row, before the commit.
     */
    public static function applyArtistMatch(EntityManager $manager, string $artistPublicId, MusicBrainzArtist $candidate, ?Closure $beforeCommit = null): MatchApplyResult
    {
        $lookup = new FakeMusicBrainzLookup();
        $lookup->artists[$candidate->id] = $candidate;
        $services = MatchApplyRace::services($manager, null);
        $artist = $services['artists']->findByPublicId(PublicId::fromString($artistPublicId)) ?? throw new \LogicException('No such artist.');
        $selection = $services['comparisons']->artist($artist, $candidate) |> MatchSelections::defaults(...);

        return ApplyMatchCommand::manual(MatchItemType::Artist, $artistPublicId, $candidate->id, $selection)
            |> MatchApplyRace::handler($manager, $lookup, $beforeCommit)(...);
    }

    /**
     * Starts the child session, which syncs the artist {"artistId"} with MusicBrainz answering its
     * ID {"mbid"} with {"name", "country", "type", "sortName"}, and prints {"outcome": "synced"}.
     *
     * @param array<string, string> $payload
     *
     * @return array{process: resource, output: resource, application: string}
     */
    public static function start(string $schema, array $payload): array
    {
        $application = 'artist_sync_' . (random_bytes(6) |> bin2hex(...));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create the contender output.');
        }
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/artist-sync.php', $schema, $application, json_encode($payload, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new \RuntimeException('Cannot start the contender.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /** @return array<string, mixed>|false the row of the artist, or false when it is gone */
    public static function row(Connection $connection, string $artistId): array|false
    {
        return $connection->fetchAssociative('SELECT name, mbid, country, type, sort_name FROM artists WHERE id = ?', [$artistId]);
    }
}
