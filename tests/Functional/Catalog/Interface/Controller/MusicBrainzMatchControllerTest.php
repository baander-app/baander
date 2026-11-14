<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Controller;

use App\Auth\Domain\Model\User;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\Catalog\Interface\LongBedMatchLibrary;
use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The candidates and comparison routes answer admins with MusicBrainz candidates and a comparison
 * built against the catalog in PostgreSQL, MusicBrainz being a fake, and refuse everyone else.
 */
final class MusicBrainzMatchControllerTest extends TestCase
{
    use LongBedMatchLibrary;

    private const string BASE = '/api/admin/metadata/match';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeLongBed();
        $this->admin = $this->createAdminUser();
    }

    public function testAnAdminGetsScoredCandidatesForAnAlbum(): void
    {
        $this->musicBrainz->releaseSearches[self::ALBUM_TITLE . '|' . self::ALBUM_ARTIST] = [self::summary($this->release)];
        $this->musicBrainz->releaseSearches['F-1 Trillion|Post Malone'] = [];

        $data = $this->albumPath('candidates') |> $this->get(...) |> $this->data(...);

        self::assertSame('album', $data['type']);
        self::assertSame($this->album->getPublicId()->toString(), $data['publicId']);
        self::assertSame(self::ALBUM_TITLE, $data['title']);
        self::assertSame(self::ALBUM_ARTIST, $data['artist']);
        self::assertCount(1, $data['candidates']);
        $candidate = $data['candidates'][0];
        self::assertSame($this->release->id, $candidate['id']);
        self::assertSame(100, $candidate['score'], 'Title, artist, track count and every track length fit.');
        self::assertSame(['Post Malone', '2024-08-16', 'XW', 3, true], [$candidate['artistCredit'], $candidate['date'], $candidate['country'], $candidate['trackCount'], $candidate['hasFrontCover']]);

        $refined = $this->get($this->albumPath('candidates') . '?title=F-1+Trillion') |> $this->data(...);
        self::assertSame(['F-1 Trillion', 'Post Malone', []], [$refined['title'], $refined['artist'], $refined['candidates']]);

        $titleAlone = $this->get($this->albumPath('candidates') . '?artist=') |> $this->data(...);
        self::assertSame([self::ALBUM_TITLE, null], [$titleAlone['title'], $titleAlone['artist']]);
        self::assertContains('findReleases:' . self::ALBUM_TITLE . '|', $this->musicBrainz->calls, 'An empty artist searches by the title alone.');
    }

    public function testMusicBrainzUnavailableAnswers503WithTheReason(): void
    {
        $this->musicBrainz->releaseSearches[self::ALBUM_TITLE . '|' . self::ALBUM_ARTIST] = new MusicBrainzUnavailable('HTTP 503');
        $this->musicBrainz->releases[$this->release->id] = new MusicBrainzUnavailable('HTTP 503');

        foreach ([$this->albumPath('candidates'), $this->albumPath('comparison') . '?candidate=' . $this->release->id] as $path) {
            $error = $this->assertJsonResponse($this->get($path), Response::HTTP_SERVICE_UNAVAILABLE);
            self::assertSame('musicbrainz_unavailable', $error['error']['details']['reason'] ?? null, $path);
        }
    }

    public function testAnAdminGetsAComparisonWithRowKeysAndNoInternalId(): void
    {
        $this->album->setLockedFields(['year']);
        $this->entityManager->flush();

        $response = $this->get($this->albumPath('comparison') . '?candidate=' . $this->release->id);
        $data = $this->data($response);

        self::assertSame(['album', $this->release->id], [$data['type'], $data['candidateId']]);
        $fields = array_column($data['fields'], null, 'key');
        self::assertSame([null, $this->release->id, true], [$fields['releaseMbid']['current'], $fields['releaseMbid']['proposed'], $fields['releaseMbid']['ticked']]);
        self::assertSame([2023, 2024, false, true, true], [$fields['year']['current'], $fields['year']['proposed'], $fields['year']['ticked'], $fields['year']['locked'], $fields['year']['changes']], 'The album\'s locked year arrives locked.');
        self::assertSame([false, false], [$fields['title']['locked'], $fields['title']['changes']]);
        self::assertSame([true, $this->release->id, false], [$fields['cover']['current'], $fields['cover']['proposed'], $fields['cover']['ticked']], 'The album has a cover, shown only as present.');
        self::assertSame('credits', $data['credits']['key']);

        self::assertCount(3, $data['tracks']);
        $duet = $data['tracks'][1];
        $duetSong = $duet['song']['publicId'];
        self::assertSame($duetSong . ':credits', $duet['credits']['key']);
        self::assertSame([['artistPublicId' => $this->duetArtist->getPublicId()->toString(), 'artistName' => 'Post Malone, Luke Combs', 'role' => 'primary', 'creditedName' => 'Post Malone, Luke Combs']], $duet['credits']['current']);
        self::assertSame(
            [['publicId' => $this->postMalone->getPublicId()->toString(), 'name' => 'Post Malone', 'takesId' => true], ['publicId' => null, 'name' => 'Luke Combs', 'takesId' => false]],
            array_column($duet['credits']['proposed'], 'artist'),
        );
        self::assertSame([[
            'kind' => 'merge',
            'artistPublicId' => $this->duetArtist->getPublicId()->toString(),
            'artistName' => 'Post Malone, Luke Combs',
            'target' => ['publicId' => $this->postMalone->getPublicId()->toString(), 'name' => 'Post Malone', 'takesId' => true],
            'requiredRows' => [$duetSong . ':credits'],
            'ticked' => true,
        ]], $data['artistActions']);

        $body = (string) $response->getContent();
        foreach ([$this->album->getId(), $this->cover->getId(), $this->postMalone->getId(), $this->duetArtist->getId()] as $internal) {
            self::assertStringNotContainsString($internal->toString(), $body);
        }
    }

    public function testAnUnknownItemOrCandidateAnswers404AndAMalformedIdAnswers422(): void
    {
        $unknown = new PublicId()->toString();

        $this->assertJsonResponse($this->get(self::BASE . '/album/' . $unknown . '/candidates'), Response::HTTP_NOT_FOUND);
        $this->assertJsonResponse($this->get(self::BASE . '/song/' . $unknown . '/comparison?candidate=' . $this->release->id), Response::HTTP_NOT_FOUND);
        $noSuchRelease = $this->assertJsonResponse($this->get($this->albumPath('comparison') . '?candidate=' . self::releaseId(9)), Response::HTTP_NOT_FOUND);
        self::assertSame(sprintf('MusicBrainz has no release "%s".', self::releaseId(9)), $noSuchRelease['error']['message']);

        $this->assertJsonResponse($this->albumPath('comparison') |> $this->get(...), Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertJsonResponse($this->get(self::BASE . '/artist/not-a-public-id/candidates'), Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['fetchRelease:' . self::releaseId(9)], $this->musicBrainz->calls, 'Nothing else reached MusicBrainz.');
    }

    public function testALibraryMemberWhoIsNotAnAdminIsRefusedBothRoutes(): void
    {
        $member = $this->createTestUser();

        foreach ([$this->albumPath('candidates'), $this->albumPath('comparison') . '?candidate=' . $this->release->id] as $path) {
            $response = $this->authenticatedRequest('GET', $path, $member);
            self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), $path);
        }
        self::assertSame([], $this->musicBrainz->calls);
    }

    private function get(string $path): Response
    {
        return $this->authenticatedRequest('GET', $path, $this->admin);
    }

    /** @return array<string, mixed> the response's data */
    private function data(Response $response): array
    {
        return $this->assertJsonResponse($response, Response::HTTP_OK, 'data')['data'];
    }
}
