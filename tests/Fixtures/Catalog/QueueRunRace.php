<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Application\Command\MusicBrainzMatch\ApplyMatchCommand;
use App\Catalog\Application\CommandHandler\MusicBrainzMatch\ApplyMatchHandler;
use App\Catalog\Application\Service\MusicBrainzMatch\AlbumMatchRequester;
use App\Catalog\Application\Service\MusicBrainzMatch\AlbumReleaseSearch;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticAlbumMatcher;
use App\Catalog\Application\Service\MusicBrainzMatch\CandidateScorer;
use App\Catalog\Application\Service\MusicBrainzMatch\MatchConfidence;
use App\Catalog\Application\Service\MusicBrainzMatch\TrackPairing;
use App\Catalog\Infrastructure\Doctrine\Repository\AlbumMatchStateRepository;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMedium;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzTrack;
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\FakeMusicBrainzLookup;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * The album match queue's production matcher and the production apply it dispatches, over the
 * disposable schema, album and release of {@see MatchApplyRace}, for QueueRunBesideWriterRaceTest.
 * The matcher asks MusicBrainz between its read of the album and the apply's locks, so the lookup's
 * hook is where a test lets another session commit. For a manual apply beside an import it also
 * holds the production match requester and what the import commits: a song and its artist.
 */
final class QueueRunRace
{
    /**
     * The matcher over the manager. Its bus hands each apply to the apply handler in this process
     * and reports a failed apply as Messenger does.
     */
    public static function matcher(EntityManager $manager, FakeMusicBrainzLookup $lookup): AutomaticAlbumMatcher
    {
        $services = MatchApplyRace::services($manager, null);

        return new AutomaticAlbumMatcher(
            albums: $services['albums'],
            snapshots: $services['snapshots'],
            musicBrainz: $lookup,
            releaseSearch: new AlbumReleaseSearch($lookup),
            scorer: new CandidateScorer(new TrackPairing()),
            confidence: new MatchConfidence(),
            bus: MatchApplyRace::handler($manager, $lookup) |> self::bus(...),
        );
    }

    /**
     * The production match requester over the manager's schema, as an import and a manual apply
     * call it, with automatic matching on or off. The queue runs it asks for are dispatched on $runs.
     */
    public static function requester(EntityManager $manager, bool $automaticMatching, MessageBusInterface $runs): AlbumMatchRequester
    {
        $services = MatchApplyRace::services($manager, null);
        $settings = new readonly class ($automaticMatching) implements SystemSettingsPortInterface {
            public function __construct(private bool $automaticMatching)
            {
            }

            public function get(string $key): bool
            {
                return $key === MetadataSettingDefinitions::AUTO_SYNC && $this->automaticMatching;
            }
        };

        return new AlbumMatchRequester(
            new AlbumMatchStateRepository($manager->getConnection()),
            $services['albums'],
            $services['songs'],
            $settings,
            $runs,
            new NativeClock(),
        );
    }

    /**
     * Stores an artist without a MusicBrainz ID that no seeded song or album credits.
     *
     * @return string the artist's ID
     */
    public static function artist(Connection $connection, string $name): string
    {
        $id = (new Uuid())->toString();
        $connection->executeStatement(
            'INSERT INTO artists (id, public_id, name, created_at, updated_at) VALUES (?, ?, ?, clock_timestamp(), clock_timestamp())',
            [$id, (new PublicId())->toString(), $name],
        );

        return $id;
    }

    /**
     * Stores a song of the album as an import commits it: tagged with its title, number and length,
     * credited to the artist as primary, and compared by no match.
     *
     * @return string the song's ID
     */
    public static function importSong(Connection $connection, string $albumId, string $title, int $track, float $length, string $artistId, string $creditedName): string
    {
        $id = (new Uuid())->toString();
        $connection->executeStatement(
            "INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, length, track, disc, hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1000000, 'audio/flac', ?, ?, 1, 'tagged', now(), now())",
            [$id, (new PublicId())->toString(), $albumId, $title, '/music/' . $id . '.flac', $length, $track],
        );
        $connection->executeStatement(
            "INSERT INTO artist_song (id, artist_id, song_id, role, credited_name) VALUES (?, ?, ?, 'primary', ?)",
            [(new Uuid())->toString(), $artistId, $id, $creditedName],
        );

        return $id;
    }

    /**
     * Leaves the seeded album as an apply of the fixture's release left it an hour ago: it holds
     * the release, and that apply compared the songs named. Every other song arrived since.
     *
     * @param list<string> $comparedSongIds
     */
    public static function matchAlbum(Connection $connection, string $albumId, array $comparedSongIds): void
    {
        $connection->executeStatement(
            "UPDATE albums SET release_mbid = ?, matched_at = clock_timestamp() - interval '1 hour' WHERE id = ?",
            [MatchApplyRace::RELEASE_ID, $albumId],
        );
        if ($comparedSongIds !== []) {
            $connection->executeStatement(
                "UPDATE songs SET match_checked_at = clock_timestamp() - interval '1 hour' WHERE id IN (?)",
                [$comparedSongIds],
                [ArrayParameterType::STRING],
            );
        }
    }

    /**
     * The fixture's lookup with the release's second track 183 seconds long instead of 200, three
     * seconds longer than its first. A song between the two lengths is within the pairing tolerance
     * of either track, so only its tagged number says which track it is.
     */
    public static function lookupOfLikeLengths(string $tag): FakeMusicBrainzLookup
    {
        $lookup = MatchApplyRace::lookup($tag);
        $release = MatchApplyRace::release($tag);
        $lookup->releases[MatchApplyRace::RELEASE_ID] = new MusicBrainzRelease(...[
            ...get_object_vars($release),
            'media' => array_map(
                static fn (MusicBrainzMedium $medium): MusicBrainzMedium => new MusicBrainzMedium($medium->position, $medium->format, $medium->title, array_map(
                    static fn (MusicBrainzTrack $track): MusicBrainzTrack => $track->position === 2
                        ? new MusicBrainzTrack(...[...get_object_vars($track), 'lengthMs' => 183_000])
                        : $track,
                    $medium->tracks,
                )),
                $release->media,
            ),
        ]);

        return $lookup;
    }

    /**
     * The rows of the album and its songs as they are stored, by ID, to tell whether a run wrote
     * any of them.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function rows(Connection $connection, string $albumId): array
    {
        $rows = [$albumId => $connection->fetchAssociative('SELECT * FROM albums WHERE id = ?', [$albumId]) ?: []];
        foreach ($connection->fetchAllAssociative('SELECT * FROM songs WHERE album_id = ? ORDER BY id', [$albumId]) as $song) {
            $rows[(string) $song['id']] = $song;
        }

        return $rows;
    }

    private static function bus(ApplyMatchHandler $handler): MessageBusInterface
    {
        return new readonly class ($handler) implements MessageBusInterface {
            public function __construct(private ApplyMatchHandler $handler)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = Envelope::wrap($message, $stamps);
                $command = $envelope->getMessage();
                if (!$command instanceof ApplyMatchCommand) {
                    throw new \LogicException('The matcher dispatches applies only.');
                }
                try {
                    $result = ($this->handler)($command);
                } catch (Throwable $failure) {
                    throw new HandlerFailedException($envelope, [$failure]);
                }

                return $envelope->with(new HandledStamp($result, ApplyMatchHandler::class . '::__invoke'));
            }
        };
    }
}
