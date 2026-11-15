<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Controller;

use App\Auth\Domain\Model\User;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\Catalog\Interface\LongBedMatchLibrary;
use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The candidates, comparison and apply routes answer admins with MusicBrainz candidates, a
 * comparison and an apply against the catalog in PostgreSQL, MusicBrainz being a fake, and refuse
 * everyone else.
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
        self::assertSame('MusicBrainz has no such release.', $noSuchRelease['error']['message']);

        $this->assertJsonResponse($this->albumPath('comparison') |> $this->get(...), Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertJsonResponse($this->get(self::BASE . '/artist/not-a-public-id/candidates'), Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['fetchRelease:' . self::releaseId(9)], $this->musicBrainz->calls, 'Nothing else reached MusicBrainz.');
    }

    public function testALibraryMemberWhoIsNotAnAdminIsRefusedEveryMatchRoute(): void
    {
        $member = $this->createTestUser();
        $body = ['candidate' => $this->release->id, 'fields' => [['key' => 'releaseMbid', 'current' => null]]];

        foreach ([
            ['GET', $this->albumPath('candidates'), []],
            ['GET', $this->albumPath('comparison') . '?candidate=' . $this->release->id, []],
            ['POST', $this->albumPath('apply'), $body],
        ] as [$method, $path, $content]) {
            $response = $this->authenticatedRequest($method, $path, $member, $content);
            self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), $path);
        }
        self::assertSame([], $this->musicBrainz->calls);
        self::assertNull($this->releaseOfAlbum());
    }

    public function testAnAdminAppliesTheDefaultTicksOfAComparison(): void
    {
        $comparison = $this->get($this->albumPath('comparison') . '?candidate=' . $this->release->id) |> $this->data(...);

        $data = $this->post($this->albumPath('apply'), self::defaultTicks($comparison)) |> $this->data(...);

        $duetArtist = $this->duetArtist->getPublicId()->toString();
        self::assertSame(['album', $this->album->getPublicId()->toString(), $this->release->id], [$data['type'], $data['publicId'], $data['candidateId']]);
        self::assertEqualsCanonicalizing(['releaseMbid', 'country'], $data['fields']);
        self::assertIsBool($data['creditsChanged']);
        self::assertSame([3, [$duetArtist], []], [$data['songsChanged'], $data['mergedArtists'], $data['removedArtists']]);
        self::assertSame($this->release->id, $this->releaseOfAlbum());
        self::assertSame(
            [['Post Malone', 'primary', 'Post Malone, Luke Combs'], ['Luke Combs', 'featured', 'Luke Combs']],
            $this->creditsOf($this->longBedSongs[1]),
            'The duet keeps its tagged text and credits its guest.',
        );
        self::assertSame(0, $this->artistCount('Post Malone, Luke Combs'), 'The combined artist merged into Post Malone.');
        self::assertSame(2023, (int) $this->entityManager->getConnection()->fetchOne('SELECT year FROM albums WHERE id = ?', [$this->album->getId()->toString()]), 'A year the admin left unticked is kept.');
    }

    public function testAnApplyAfterTheItemChangedAnswers409StaleAndWritesNothing(): void
    {
        $comparison = $this->get($this->albumPath('comparison') . '?candidate=' . $this->release->id) |> $this->data(...);
        $body = self::defaultTicks($comparison);
        $body['fields'][] = ['key' => 'year', 'current' => 2022];

        $error = $this->assertJsonResponse($this->post($this->albumPath('apply'), $body), Response::HTTP_CONFLICT);

        self::assertSame('match_stale', $error['error']['details']['reason'] ?? null);
        self::assertSame('The year changed since the comparison. Compare the match again.', $error['error']['message']);
        self::assertNull($this->releaseOfAlbum());
        self::assertSame(1, $this->artistCount('Post Malone, Luke Combs'));
    }

    public function testAnApplyWhoseTickedCoverTheCoverArtArchiveRefusesAnswers409AndWritesNothingAndAppliesOnceTheCoverIsUnticked(): void
    {
        $this->lookup->covers[$this->release->id] = new MusicBrainzRejected(403);
        $comparison = $this->get($this->albumPath('comparison') . '?candidate=' . $this->release->id) |> $this->data(...);
        $unticked = self::defaultTicks($comparison);
        $ticked = $unticked;
        $ticked['fields'][] = ['key' => 'cover', 'current' => true];

        $error = $this->assertJsonResponse($this->post($this->albumPath('apply'), $ticked), Response::HTTP_CONFLICT);

        self::assertSame('cover_refused', $error['error']['details']['reason'] ?? null);
        self::assertSame('The Cover Art Archive refused the cover with HTTP 403. Nothing was written; untick the cover to apply the rest.', $error['error']['message']);
        self::assertNull($this->releaseOfAlbum());
        self::assertSame(1, $this->artistCount('Post Malone, Luke Combs'));
        self::assertSame($this->cover->getId()->toString(), $this->coverOfAlbum());

        $data = $this->post($this->albumPath('apply'), $unticked) |> $this->data(...);

        self::assertSame(
            ['type', 'publicId', 'candidateId', 'fields', 'creditsChanged', 'songsChanged', 'mergedArtists', 'removedArtists'],
            array_keys($data),
            'The answer says what was written and nothing about the cover.',
        );
        self::assertEqualsCanonicalizing(['releaseMbid', 'country'], $data['fields']);
        self::assertSame($this->release->id, $this->releaseOfAlbum());
        self::assertSame($this->cover->getId()->toString(), $this->coverOfAlbum());
    }

    public function testAnApplyWhoseReleaseLookupMusicBrainzRefusesAnswers409RejectedAndWritesNothing(): void
    {
        $comparison = $this->get($this->albumPath('comparison') . '?candidate=' . $this->release->id) |> $this->data(...);
        $this->musicBrainz->releases[$this->release->id] = new MusicBrainzRejected(400);

        $error = $this->assertJsonResponse($this->post($this->albumPath('apply'), self::defaultTicks($comparison)), Response::HTTP_CONFLICT);

        self::assertSame('musicbrainz_rejected', $error['error']['details']['reason'] ?? null);
        self::assertNull($this->releaseOfAlbum());
    }

    public function testAMalformedSelectionOrTypeAnswers422(): void
    {
        $selection = $this->assertJsonResponse(
            $this->post($this->albumPath('apply'), ['candidate' => $this->release->id, 'fields' => [['key' => 'colour', 'current' => 'red']]]),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
        self::assertSame(['fields[0].key' => ['"colour" names no field row.']], $selection['error']['details']);

        $publicId = $this->album->getPublicId()->toString();
        foreach ([
            ['GET', self::BASE . '/release/' . $publicId . '/candidates', []],
            ['GET', self::BASE . '/albums/' . $publicId . '/comparison?candidate=' . $this->release->id, []],
            ['POST', self::BASE . '/record/' . $publicId . '/apply', ['candidate' => $this->release->id]],
        ] as [$method, $path, $content]) {
            $error = $this->assertJsonResponse($this->authenticatedRequest($method, $path, $this->admin, $content), Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSame('The type must be album, song or artist.', $error['error']['message'], $path);
        }
        self::assertSame([], $this->musicBrainz->calls);
        self::assertNull($this->releaseOfAlbum());
    }

    private function get(string $path): Response
    {
        return $this->authenticatedRequest('GET', $path, $this->admin);
    }

    /**
     * The entity manager is cleared first: the apply merges and deletes artists the fixture left
     * managed, and the request shares the test's entity manager.
     *
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body): Response
    {
        $this->entityManager->clear();

        return $this->authenticatedRequest('POST', $path, $this->admin, $body);
    }

    private function coverOfAlbum(): ?string
    {
        $cover = $this->entityManager->getConnection()->fetchOne('SELECT cover_image_id::text FROM albums WHERE id = ?', [$this->album->getId()->toString()]);

        return is_string($cover) ? $cover : null;
    }

    /** @return array<string, mixed> the response's data */
    private function data(Response $response): array
    {
        return $this->assertJsonResponse($response, Response::HTTP_OK, 'data')['data'];
    }
}
