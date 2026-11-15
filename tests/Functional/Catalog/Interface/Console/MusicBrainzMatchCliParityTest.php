<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Console;

use App\Auth\Domain\Model\User;
use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\Catalog\Interface\LongBedMatchLibrary;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * app:metadata:match:search, app:metadata:match:compare and app:metadata:match:apply reach the
 * use cases the candidates, comparison and apply routes reach, print the routes' data with --json,
 * and report the routes' failures with the same messages and the matching exit codes.
 */
final class MusicBrainzMatchCliParityTest extends TestCase
{
    use LongBedMatchLibrary;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeLongBed();
        $this->admin = $this->createAdminUser();
    }

    public function testSearchPrintsTheApiData(): void
    {
        $this->musicBrainz->releaseSearches[self::ALBUM_TITLE . '|' . self::ALBUM_ARTIST] = [self::summary($this->release)];
        $this->musicBrainz->releaseSearches['F-1 Trillion|Luke Combs'] = [self::summary($this->release)];
        $publicId = $this->album->getPublicId()->toString();

        $this->musicBrainz->releaseSearches[self::ALBUM_TITLE . '|'] = [self::summary($this->release)];
        $searches = [
            '' => [],
            '?title=F-1+Trillion&artist=Luke+Combs' => ['--title' => 'F-1 Trillion', '--artist' => 'Luke Combs'],
            '?artist=' => ['--no-artist' => true],
        ];

        foreach ($searches as $query => $options) {
            $api = $this->assertJsonResponse($this->get($this->albumPath('candidates') . $query), 200, 'data')['data'];

            $search = $this->command('app:metadata:match:search');
            self::assertSame(Command::SUCCESS, $search->execute(['type' => 'album', 'public-id' => $publicId, '--json' => true, ...$options]), $search->getDisplay());

            self::assertSame($api, $this->json($search));
            self::assertSame([$this->release->id], array_column($api['candidates'], 'id'));
        }
    }

    public function testSearchOfAnAlbumWhoseOwnTitleFindsNoReleasePrintsTheApiDataOfItsShortenedTitle(): void
    {
        $stored = 'Ten Thousand Fists [Reprise Records,9362-49433-2,EU]';
        $album = new AlbumEntity(new PublicId(), $this->album->getLibrary(), $stored, 'album');
        $disturbed = new ArtistEntity(new PublicId(), 'Disturbed');
        foreach ([$album, $disturbed, new ArtistAlbumEntity($disturbed, $album, 'primary', 'Disturbed')] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $release = self::releaseWith(self::release(self::releaseId(2), [14], title: 'Ten Thousand Fists'), artistCredit: self::credit('Disturbed'));
        $this->musicBrainz->releaseSearches['Ten Thousand Fists|Disturbed'] = [self::summary($release)];
        $this->musicBrainz->releases[$release->id] = $release;
        $publicId = $album->getPublicId()->toString();

        $api = $this->assertJsonResponse($this->get(sprintf('/api/admin/metadata/match/album/%s/candidates', $publicId)), 200, 'data')['data'];

        $search = $this->command('app:metadata:match:search');
        self::assertSame(Command::SUCCESS, $search->execute(['type' => 'album', 'public-id' => $publicId, '--json' => true]), $search->getDisplay());

        self::assertSame($api, $this->json($search));
        self::assertSame('Ten Thousand Fists', $api['title'], 'The list reports the title it searched last.');
        self::assertSame([$release->id], array_column($api['candidates'], 'id'));
        $searches = ['findReleases:' . $stored . '|Disturbed', 'findReleases:Ten Thousand Fists|Disturbed', 'fetchRelease:' . $release->id];
        self::assertSame([...$searches, ...$searches], $this->musicBrainz->calls, 'The route and the command each search twice.');
        self::assertSame($stored, $this->entityManager->getConnection()->fetchOne('SELECT title FROM albums WHERE id = ?', [$album->getId()->toString()]));
    }

    public function testCompareAnAlbumPrintsTheApiData(): void
    {
        $api = $this->assertJsonResponse($this->get($this->albumPath('comparison') . '?candidate=' . $this->release->id), 200, 'data')['data'];

        $compare = $this->command('app:metadata:match:compare');
        self::assertSame(Command::SUCCESS, $compare->execute([
            'type' => 'album',
            'public-id' => $this->album->getPublicId()->toString(),
            'candidate' => $this->release->id,
            '--json' => true,
        ]), $compare->getDisplay());

        self::assertSame($api, $this->json($compare));
        self::assertCount(1, $api['artistActions']);
    }

    public function testCompareASongPrintsTheApiData(): void
    {
        $duet = $this->longBedSongs[1]->getPublicId()->toString();
        $recording = new MusicBrainzRecording(self::recordingId(1, 2), 'Track 1-2', 0, 157_000, null, self::credit(self::ALBUM_ARTIST, 'Luke Combs'), []);
        $this->musicBrainz->recordings[$recording->id] = $recording;

        $api = $this->assertJsonResponse($this->get(sprintf('/api/admin/metadata/match/song/%s/comparison?candidate=%s', $duet, $recording->id)), 200, 'data')['data'];

        $compare = $this->command('app:metadata:match:compare');
        self::assertSame(Command::SUCCESS, $compare->execute(['type' => 'song', 'public-id' => $duet, 'candidate' => $recording->id, '--json' => true]), $compare->getDisplay());

        self::assertSame($api, $this->json($compare));
        self::assertSame('credits', $api['credits']['key']);
        self::assertSame(['credits'], $api['artistActions'][0]['requiredRows'] ?? null);
    }

    public function testAnOutageAnswers503AndExits1WithTheSameMessage(): void
    {
        $this->musicBrainz->releaseSearches[self::ALBUM_TITLE . '|' . self::ALBUM_ARTIST] = new MusicBrainzUnavailable('HTTP 503');

        $error = $this->assertJsonResponse($this->albumPath('candidates') |> $this->get(...), 503);
        self::assertSame('musicbrainz_unavailable', $error['error']['details']['reason'] ?? null);

        $search = $this->command('app:metadata:match:search');
        self::assertSame(Command::FAILURE, $search->execute(['type' => 'album', 'public-id' => $this->album->getPublicId()->toString()]));
        self::assertStringContainsString($error['error']['message'], $search->getDisplay() |> $this->flatten(...));
    }

    public function testARejectedLookupAnswers409AndExits1WithTheSameMessage(): void
    {
        $this->musicBrainz->releases[$this->release->id] = new MusicBrainzRejected(400);

        $error = $this->assertJsonResponse($this->get($this->albumPath('comparison') . '?candidate=' . $this->release->id), 409);
        self::assertSame('musicbrainz_rejected', $error['error']['details']['reason'] ?? null);

        $compare = $this->command('app:metadata:match:compare');
        self::assertSame(Command::FAILURE, $compare->execute(['type' => 'album', 'public-id' => $this->album->getPublicId()->toString(), 'candidate' => $this->release->id]));
        self::assertStringContainsString($error['error']['message'], $compare->getDisplay() |> $this->flatten(...));
    }

    public function testAnUnknownCandidateExits1AndAMalformedOneExits2LikeTheRoutesAnswers(): void
    {
        $publicId = $this->album->getPublicId()->toString();
        $unknown = self::releaseId(9);

        $notFound = $this->assertJsonResponse($this->get($this->albumPath('comparison') . '?candidate=' . $unknown), 404);
        self::assertSame('MusicBrainz has no such release.', $notFound['error']['message'], 'The message names no ID, which the API would mask.');
        $compare = $this->command('app:metadata:match:compare');
        self::assertSame(Command::FAILURE, $compare->execute(['type' => 'album', 'public-id' => $publicId, 'candidate' => $unknown]));
        self::assertStringContainsString($notFound['error']['message'], $compare->getDisplay() |> $this->flatten(...));

        $invalid = $this->assertJsonResponse($this->get($this->albumPath('comparison') . '?candidate=not-an-id'), 422);
        $malformed = $this->command('app:metadata:match:compare');
        self::assertSame(Command::INVALID, $malformed->execute(['type' => 'album', 'public-id' => $publicId, 'candidate' => 'not-an-id']));
        self::assertStringContainsString($invalid['error']['message'], $malformed->getDisplay() |> $this->flatten(...));
    }

    public function testApplyWithoutASelectionAppliesTheDefaultTicksAndPrintsTheApiData(): void
    {
        // The apply merges and deletes an artist the fixture left managed in the shared entity manager.
        $this->entityManager->clear();
        $apply = $this->command('app:metadata:match:apply');

        self::assertSame(Command::SUCCESS, $apply->execute([
            'type' => 'album',
            'public-id' => $this->album->getPublicId()->toString(),
            'candidate' => $this->release->id,
            '--force' => true,
            '--json' => true,
        ]), $apply->getDisplay());

        $data = $this->json($apply);
        self::assertSame(['album', $this->album->getPublicId()->toString(), $this->release->id], [$data['type'], $data['publicId'], $data['candidateId']]);
        self::assertEqualsCanonicalizing(['releaseMbid', 'country'], $data['fields']);
        self::assertSame([3, [$this->duetArtist->getPublicId()->toString()], []], [$data['songsChanged'], $data['mergedArtists'], $data['removedArtists']]);
        self::assertSame($this->release->id, $this->releaseOfAlbum());
        self::assertSame([['Post Malone', 'primary', 'Post Malone, Luke Combs'], ['Luke Combs', 'featured', 'Luke Combs']], $this->creditsOf($this->longBedSongs[1]));
        self::assertSame(0, $this->artistCount('Post Malone, Luke Combs'));
    }

    /**
     * An import commits a song of the album while the apply asks MusicBrainz, and while automatic
     * matching is on also its request for the album. The apply compares the songs it read and
     * removes the album's match state. Both entry points then leave the album alike: waiting for a
     * match again while automatic matching is on, and without a match state while it is off.
     *
     * @param array{state: ?string, source: ?string, dueLater: ?bool, uncomparedSongs: int, queuedRuns: list<int>} $expected
     */
    #[TestWith([true, ['state' => 'requested', 'source' => 'ingest', 'dueLater' => true, 'uncomparedSongs' => 1, 'queuedRuns' => [300_000]]], 'automatic matching on')]
    #[TestWith([false, ['state' => null, 'source' => null, 'dueLater' => null, 'uncomparedSongs' => 1, 'queuedRuns' => []]], 'automatic matching off')]
    public function testAnApplyBesideAnImportLeavesTheSameMatchStateFromTheApiAndTheConsole(bool $automaticMatching, array $expected): void
    {
        static::getContainer()->get(SystemSettingStoreInterface::class)->save([MetadataSettingDefinitions::AUTO_SYNC => $automaticMatching]);
        $body = $this->assertJsonResponse($this->get($this->albumPath('comparison') . '?candidate=' . $this->release->id), 200, 'data')['data'] |> self::defaultTicks(...);
        $connection = $this->entityManager->getConnection();
        $left = [];

        foreach (['api', 'console'] as $entryPoint) {
            $this->entityManager->clear();
            $this->asyncTransport()->reset();
            // Each entry point applies to the album as the fixture stored it: what the other wrote is rolled back.
            $connection->beginTransaction();
            $this->musicBrainz->duringReleaseLookup = function () use ($automaticMatching): void {
                $this->musicBrainz->duringReleaseLookup = null;
                $this->importSong($automaticMatching);
            };

            if ($entryPoint === 'api') {
                $this->assertJsonResponse($this->authenticatedRequest('POST', $this->albumPath('apply'), $this->admin, $body), 200, 'data');
            } else {
                $apply = $this->command('app:metadata:match:apply');
                self::assertSame(Command::SUCCESS, $apply->execute([
                    'type' => 'album',
                    'public-id' => $this->album->getPublicId()->toString(),
                    'candidate' => $this->release->id,
                    '--selection' => json_encode($body, JSON_THROW_ON_ERROR),
                    '--force' => true,
                    '--json' => true,
                ]), $apply->getDisplay());
            }

            self::assertSame($this->release->id, $this->releaseOfAlbum(), $entryPoint);
            $left[$entryPoint] = $this->matchStateLeft();
            $connection->rollBack();
        }

        self::assertSame(['api' => $expected, 'console' => $expected], $left);
    }

    public function testAStaleSelectionAnswers409AndExits1WithTheSameMessage(): void
    {
        $comparison = $this->assertJsonResponse($this->get($this->albumPath('comparison') . '?candidate=' . $this->release->id), 200, 'data')['data'];
        $body = self::defaultTicks($comparison);
        $body['fields'][] = ['key' => 'year', 'current' => 2022];

        $this->entityManager->clear();
        $error = $this->assertJsonResponse($this->authenticatedRequest('POST', $this->albumPath('apply'), $this->admin, $body), 409);
        self::assertSame('match_stale', $error['error']['details']['reason'] ?? null);

        $apply = $this->command('app:metadata:match:apply');
        self::assertSame(Command::FAILURE, $apply->execute([
            'type' => 'album',
            'public-id' => $this->album->getPublicId()->toString(),
            'candidate' => $this->release->id,
            '--selection' => json_encode($body, JSON_THROW_ON_ERROR),
            '--force' => true,
        ]));
        self::assertStringContainsString($error['error']['message'], $apply->getDisplay() |> $this->flatten(...));
        self::assertNull($this->releaseOfAlbum());
        self::assertSame(1, $this->artistCount('Post Malone, Luke Combs'));
    }

    public function testARefusedCoverAnswers409AndExits1WithTheSameMessage(): void
    {
        $this->lookup->covers[$this->release->id] = new MusicBrainzRejected(403);
        $comparison = $this->assertJsonResponse($this->get($this->albumPath('comparison') . '?candidate=' . $this->release->id), 200, 'data')['data'];
        $body = self::defaultTicks($comparison);
        $body['fields'][] = ['key' => 'cover', 'current' => true];

        $this->entityManager->clear();
        $error = $this->assertJsonResponse($this->authenticatedRequest('POST', $this->albumPath('apply'), $this->admin, $body), 409);
        self::assertSame('cover_refused', $error['error']['details']['reason'] ?? null);

        $apply = $this->command('app:metadata:match:apply');
        self::assertSame(Command::FAILURE, $apply->execute([
            'type' => 'album',
            'public-id' => $this->album->getPublicId()->toString(),
            'candidate' => $this->release->id,
            '--selection' => json_encode($body, JSON_THROW_ON_ERROR),
            '--force' => true,
        ]));
        self::assertStringContainsString($error['error']['message'], $apply->getDisplay() |> $this->flatten(...));
        self::assertNull($this->releaseOfAlbum());
        self::assertSame(1, $this->artistCount('Post Malone, Luke Combs'));
    }

    public function testAMalformedSelectionAnswers422AndExits2WithTheSameMessages(): void
    {
        $body = ['fields' => [['key' => 'colour', 'current' => 'red']]];

        $error = $this->assertJsonResponse($this->authenticatedRequest('POST', $this->albumPath('apply'), $this->admin, ['candidate' => $this->release->id, ...$body]), 422);

        $apply = $this->command('app:metadata:match:apply');
        self::assertSame(Command::INVALID, $apply->execute([
            'type' => 'album',
            'public-id' => $this->album->getPublicId()->toString(),
            'candidate' => $this->release->id,
            '--selection' => json_encode($body, JSON_THROW_ON_ERROR),
            '--force' => true,
        ]));
        $display = $apply->getDisplay() |> $this->flatten(...);
        self::assertStringContainsString($error['error']['message'], $display);
        self::assertStringContainsString('fields[0].key: ' . $error['error']['details']['fields[0].key'][0], $display);
        self::assertSame([], $this->musicBrainz->calls);
    }

    public function testAnUnknownTypeAnswers422AndExits2WithTheSameMessage(): void
    {
        $publicId = $this->album->getPublicId()->toString();
        $error = $this->assertJsonResponse($this->get('/api/admin/metadata/match/release/' . $publicId . '/candidates'), 422);
        $applyError = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/admin/metadata/match/release/' . $publicId . '/apply', $this->admin, ['candidate' => $this->release->id]), 422);
        self::assertSame($error['error']['message'], $applyError['error']['message']);

        foreach ([
            'app:metadata:match:search' => [],
            'app:metadata:match:compare' => ['candidate' => $this->release->id],
            'app:metadata:match:apply' => ['candidate' => $this->release->id, '--force' => true],
        ] as $name => $arguments) {
            $command = $this->command($name);
            self::assertSame(Command::INVALID, $command->execute(['type' => 'release', 'public-id' => $publicId, ...$arguments]), $name);
            self::assertStringContainsString($error['error']['message'], $command->getDisplay() |> $this->flatten(...), $name);
        }
        self::assertSame([], $this->musicBrainz->calls);
    }

    private function get(string $path): Response
    {
        return $this->authenticatedRequest('GET', $path, $this->admin);
    }

    /** @return array<array-key, mixed> */
    private function json(CommandTester $tester): array
    {
        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** The output on one line, as SymfonyStyle wraps long error messages. */
    private function flatten(string $output): string
    {
        return (string) preg_replace('/\s+/', ' ', $output);
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester(new Application($this->client->getKernel())->find($name));
    }

    /**
     * Stores a fourth song of the album as an import commits it, credited to Post Malone and
     * compared by no match, and, when the import asks for matches, its request for the album, whose
     * settling delay has passed.
     */
    private function importSong(bool $requestsAMatch): void
    {
        $connection = $this->entityManager->getConnection();
        $songId = new Uuid()->toString();
        $connection->executeStatement(
            "INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, length, track, disc, created_at, updated_at) VALUES (?, ?, ?, 'Losers', ?, 1000, 'audio/flac', 181.5, 4, 1, clock_timestamp(), clock_timestamp())",
            [$songId, new PublicId()->toString(), $this->album->getId()->toString(), '/media/long-bed-match-import/' . $songId . '.flac'],
        );
        $connection->executeStatement(
            "INSERT INTO artist_song (id, artist_id, song_id, role, credited_name) VALUES (?, ?, ?, 'primary', ?)",
            [new Uuid()->toString(), $this->postMalone->getId()->toString(), $songId, self::ALBUM_ARTIST],
        );
        if ($requestsAMatch) {
            static::getContainer()->get(AlbumMatchStateRepositoryInterface::class)
                ->request($this->album->getId(), AlbumMatchSource::Ingest, new \DateTimeImmutable('-1 minute'));
        }
    }

    /**
     * What an apply left of the album's place in automatic matching: its match state, whether that
     * falls due later than now, the songs no match has compared, and the delay in milliseconds of
     * each album match queue run on its way.
     *
     * @return array{state: ?string, source: ?string, dueLater: ?bool, uncomparedSongs: int, queuedRuns: list<int|null>}
     */
    private function matchStateLeft(): array
    {
        $connection = $this->entityManager->getConnection();
        $albumId = $this->album->getId()->toString();
        $state = $connection->fetchAssociative('SELECT state, source, due_at > clock_timestamp() AS due_later FROM album_match_states WHERE album_id = ?', [$albumId]);
        $runs = array_filter($this->asyncTransport()->getSent(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof DrainAlbumMatchesMessage);

        return [
            'state' => $state === false ? null : (string) $state['state'],
            'source' => $state === false ? null : (string) $state['source'],
            'dueLater' => $state === false ? null : (bool) $state['due_later'],
            'uncomparedSongs' => (int) $connection->fetchOne('SELECT count(*) FROM songs WHERE album_id = ? AND match_checked_at IS NULL', [$albumId]),
            'queuedRuns' => array_map(static fn (Envelope $run): ?int => $run->last(DelayStamp::class)?->getDelay(), array_values($runs)),
        ];
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
