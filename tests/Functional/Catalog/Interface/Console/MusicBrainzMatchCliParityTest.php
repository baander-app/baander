<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Console;

use App\Auth\Domain\Model\User;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Tests\Functional\Catalog\Interface\LongBedMatchLibrary;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

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
}
