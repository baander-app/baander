<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch;

use App\Catalog\Application\Query\MusicBrainzMatch\ArtistCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCandidates;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Query\MusicBrainzMatch\RecordingCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\ReleaseCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\SearchMatchCandidatesQuery;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\SearchMatchCandidatesHandler;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtistCredit;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzLabel;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecordingRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseGroup;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Domain\Model\PublicId;
use PHPUnit\Framework\TestCase;

final class SearchMatchCandidatesHandlerTest extends TestCase
{
    use MatchQueryFixtures;

    private const string SEARCH = self::ALBUM_TITLE . '|' . self::ALBUM_ARTIST;

    protected function setUp(): void
    {
        $this->setUpLibrary();
    }

    public function testAnAlbumSearchLooksUpTheFiveBestHitsAndListsThemBestFirstWithTheirDetails(): void
    {
        $album = $this->longBed();
        $explicit = self::releaseWith(
            self::release(self::releaseId(1)),
            labels: [new MusicBrainzLabel('9e1abe10-0000-4000-8000-000000000001', 'Mercury Records', 'B0040000-02')],
            releaseGroup: new MusicBrainzReleaseGroup('a1b0f000-0000-4000-8000-000000000001', self::ALBUM_TITLE, 'Album', '2024-08-16'),
            barcode: '602475920000',
            hasFrontCover: true,
        );
        $clean = self::release(self::releaseId(2), title: self::ALBUM_TITLE . ' (Clean)');
        $deluxe = self::release(self::releaseId(3), [18, 10]);
        $short = self::release(self::releaseId(4), [18], title: 'F-1 Trillion');
        $austin = self::release(self::releaseId(5), [17], title: 'Austin');
        $farOff = self::release(self::releaseId(6), [12], title: 'Long Bed');
        $unrelated = self::release(self::releaseId(7), [10], title: 'Nothing Alike');
        $stranger = self::credit('Someone Else');
        // MusicBrainz lists the hits worst first, so only Baander's own ranking puts the album first.
        $this->musicBrainz->releaseSearches[self::SEARCH] = [
            self::summary($unrelated, $stranger),
            self::summary($farOff, $stranger),
            self::summary($austin),
            self::summary($short),
            self::summary($deluxe),
            self::summary($clean),
            self::summary($explicit),
        ];
        foreach ([$explicit, $clean, $deluxe, $short, $austin, $farOff, $unrelated] as $release) {
            $this->musicBrainz->releases[$release->id] = $release;
        }

        $result = $this->search($album);

        self::assertSame(MatchItemType::Album, $result->type);
        self::assertSame(self::ALBUM_TITLE, $result->title);
        self::assertSame(self::ALBUM_ARTIST, $result->artist);
        self::assertSame(['findReleases:' . self::SEARCH], array_slice($this->musicBrainz->calls, 0, 1));
        self::assertEqualsCanonicalizing(
            [$explicit->id, $clean->id, $deluxe->id, $short->id, $austin->id],
            $this->musicBrainz->fetchedReleases(),
            'Only the five best-ranked hits are looked up.',
        );
        self::assertSame([$explicit->id, $clean->id, $deluxe->id, $short->id, $austin->id], self::ids($result->candidates));
        $scores = array_column($result->candidates, 'score');
        self::assertSame(100, $scores[0]);
        $descending = $scores;
        rsort($descending);
        self::assertSame($descending, $scores, 'Best first.');

        $best = $result->candidates[0];
        self::assertInstanceOf(ReleaseCandidate::class, $best);
        self::assertSame(self::ALBUM_TITLE, $best->title);
        self::assertSame(self::ALBUM_ARTIST, $best->artistCredit);
        self::assertSame('2024-08-16', $best->date);
        self::assertSame('XW', $best->country);
        self::assertSame(['Mercury Records'], $best->labels);
        self::assertSame(['B0040000-02'], $best->catalogNumbers);
        self::assertSame(['Digital Media'], $best->formats);
        self::assertSame(27, $best->trackCount);
        self::assertSame(2, $best->mediumCount);
        self::assertSame('602475920000', $best->barcode);
        self::assertSame('a1b0f000-0000-4000-8000-000000000001', $best->releaseGroupId);
        self::assertTrue($best->hasFrontCover);
    }

    public function testARefinedSearchWithAnEditedTitleAndArtistReplacesTheCandidates(): void
    {
        $album = $this->longBed();
        $original = self::release(self::releaseId(1));
        $refined = self::release(self::releaseId(8), title: 'F-1 Trillion: Long Bed (Deluxe)');
        $this->musicBrainz->releaseSearches[self::SEARCH] = [self::summary($original)];
        $this->musicBrainz->releaseSearches['Long Bed|Posty'] = [self::summary($refined)];
        $this->musicBrainz->releases = [$original->id => $original, $refined->id => $refined];

        $result = $this->search($album, '  Long Bed ', 'Posty');

        self::assertSame('Long Bed', $result->title);
        self::assertSame('Posty', $result->artist);
        self::assertSame(['findReleases:Long Bed|Posty', 'fetchRelease:' . $refined->id], $this->musicBrainz->calls);
        self::assertSame([$refined->id], self::ids($result->candidates));
    }

    /**
     * For a compilation or a wrongly tagged album, the admin searches by the title alone. The fit
     * score still compares each candidate with the album's own artist.
     */
    public function testABlankArtistSearchesByTheTitleAloneWhileNoEditSearchesTheItemsOwnArtist(): void
    {
        $album = $this->longBed();
        $release = self::release(self::releaseId(1));
        $this->musicBrainz->releaseSearches[self::ALBUM_TITLE . '|'] = [self::summary($release)];
        $this->musicBrainz->releaseSearches[self::SEARCH] = [self::summary($release)];
        $this->musicBrainz->releases[$release->id] = $release;

        $titleAlone = $this->search($album, artist: ' ');
        $ownArtist = $this->search($album);

        self::assertNull($titleAlone->artist);
        self::assertSame(self::ALBUM_ARTIST, $ownArtist->artist);
        self::assertSame(['findReleases:' . self::ALBUM_TITLE . '|', 'fetchRelease:' . $release->id, 'findReleases:' . self::SEARCH, 'fetchRelease:' . $release->id], $this->musicBrainz->calls);
        self::assertSame(array_column($ownArtist->candidates, 'score'), array_column($titleAlone->candidates, 'score'), 'Both score against the album\'s own artist.');
    }

    public function testABlankArtistSearchesASongsRecordingsByTheTitleAlone(): void
    {
        $album = $this->addAlbum();
        $song = $this->addSongsOf($album, self::release(self::releaseId(1), [4]))[3];
        $this->catalog->creditSong($song, $this->catalog->artist('Post Malone, Luke Combs'), ArtistRole::Primary);

        $result = new SearchMatchCandidatesHandler($this->musicBrainz, $this->scorer(), $this->snapshots())(
            new SearchMatchCandidatesQuery(MatchItemType::Song, $song->getPublicId()->toString(), artist: ''),
        );

        self::assertNull($result->artist);
        self::assertSame(['findRecordings:Track 1-4|'], $this->musicBrainz->calls);
    }

    public function testAnAlbumWithoutAPrimaryCreditIsSearchedByItsTitleAlone(): void
    {
        $album = $this->addAlbum('Now 100');

        $result = $this->search($album);

        self::assertNull($result->artist);
        self::assertSame(['findReleases:Now 100|'], $this->musicBrainz->calls);
        self::assertSame([], $result->candidates, 'A search that finds nothing is an empty list.');
    }

    public function testAHitMusicBrainzNoLongerHasIsLeftOut(): void
    {
        $album = $this->longBed();
        $kept = self::release(self::releaseId(1));
        $gone = self::release(self::releaseId(2));
        $this->musicBrainz->releaseSearches[self::SEARCH] = [self::summary($kept), self::summary($gone)];
        $this->musicBrainz->releases = [$kept->id => $kept];

        self::assertSame([$kept->id], self::ids($this->search($album)->candidates));
    }

    public function testMusicBrainzUnavailableForTheSearchAnswers503InsteadOfAnEmptyList(): void
    {
        $album = $this->longBed();
        $this->musicBrainz->releaseSearches[self::SEARCH] = new MusicBrainzUnavailable('HTTP 503');

        $exception = $this->searchFailure($album);

        self::assertInstanceOf(ServiceUnavailableException::class, $exception);
        self::assertSame(['reason' => 'musicbrainz_unavailable'], $exception->details);
    }

    public function testMusicBrainzUnavailableForALookupAnswers503InsteadOfAShorterList(): void
    {
        $album = $this->longBed();
        $first = self::release(self::releaseId(1));
        $second = self::release(self::releaseId(2));
        $this->musicBrainz->releaseSearches[self::SEARCH] = [self::summary($first), self::summary($second)];
        $this->musicBrainz->releases = [$first->id => $first, $second->id => new MusicBrainzUnavailable('timed out')];

        $exception = $this->searchFailure($album);

        self::assertInstanceOf(ServiceUnavailableException::class, $exception);
        self::assertSame(['reason' => 'musicbrainz_unavailable'], $exception->details);
    }

    public function testARequestMusicBrainzRejectsAnswers409(): void
    {
        $album = $this->longBed();
        $this->musicBrainz->releaseSearches[self::SEARCH] = new MusicBrainzRejected(400);

        $exception = $this->searchFailure($album);

        self::assertInstanceOf(ConflictException::class, $exception);
        self::assertSame(['reason' => 'musicbrainz_rejected'], $exception->details);
    }

    public function testAnUnknownAlbumAnswers404WithoutAskingMusicBrainz(): void
    {
        $handler = new SearchMatchCandidatesHandler($this->musicBrainz, $this->scorer(), $this->snapshots());

        try {
            $handler(new SearchMatchCandidatesQuery(MatchItemType::Album, new PublicId()->toString()));
            self::fail('An unknown album is not found.');
        } catch (NotFoundException) {
            self::assertSame([], $this->musicBrainz->calls);
        }
    }

    public function testAMalformedPublicIdAnswers422WithoutAskingMusicBrainz(): void
    {
        $handler = new SearchMatchCandidatesHandler($this->musicBrainz, $this->scorer(), $this->snapshots());

        $this->expectException(InvalidInputException::class);

        try {
            $handler(new SearchMatchCandidatesQuery(MatchItemType::Song, 'not-a-public-id'));
        } finally {
            self::assertSame([], $this->musicBrainz->calls);
        }
    }

    public function testASongSearchListsRecordingsByTheirFitToTheSongBestFirst(): void
    {
        $album = $this->addAlbum();
        $song = $this->addSongsOf($album, self::release(self::releaseId(1), [4]))[3];
        $postMaloneLukeCombs = $this->catalog->artist('Post Malone, Luke Combs');
        $this->catalog->creditSong($song, $postMaloneLukeCombs, ArtistRole::Primary);
        $lengthMs = (int) round(($song->getLength() ?? 0.0) * 1000);
        $duet = self::recording(1, 'Track 1-4', self::credit('Post Malone', 'Luke Combs'), $lengthMs);
        $live = self::recording(2, 'Track 1-4 (Live)', self::credit('Post Malone', 'Luke Combs'), $lengthMs + 10_000);
        $cover = self::recording(3, 'Track 1-4', self::credit('Someone Else'), null);
        $this->musicBrainz->recordingSearch = [$cover, $live, $duet];

        $result = new SearchMatchCandidatesHandler($this->musicBrainz, $this->scorer(), $this->snapshots())(
            new SearchMatchCandidatesQuery(MatchItemType::Song, $song->getPublicId()->toString()),
        );

        self::assertSame(['findRecordings:Track 1-4|Post Malone, Luke Combs'], $this->musicBrainz->calls);
        self::assertSame([$duet->id, $live->id, $cover->id], self::ids($result->candidates));
        $best = $result->candidates[0];
        self::assertInstanceOf(RecordingCandidate::class, $best);
        self::assertSame(100, $best->score);
        self::assertSame('Post Malone feat. Luke Combs', $best->artistCredit);
        self::assertSame($lengthMs, $best->lengthMs);
        self::assertSame(self::ALBUM_TITLE, $best->releases[0]->title);
    }

    public function testAnArtistSearchListsArtistsByTheirFitToTheArtistBestFirst(): void
    {
        $artist = $this->catalog->artist('Headhunterz');
        $exact = self::musicBrainzArtist('6c1e0000-0000-4000-8000-000000000001', 'Headhunterz', 'Dutch hardstyle DJ');
        $near = self::musicBrainzArtist('6c1e0000-0000-4000-8000-000000000002', 'Head Hunters', 'jazz-funk band');
        $this->musicBrainz->artistSearch = [$near, $exact];

        $result = new SearchMatchCandidatesHandler($this->musicBrainz, $this->scorer(), $this->snapshots())(
            new SearchMatchCandidatesQuery(MatchItemType::Artist, $artist->getPublicId()->toString()),
        );

        self::assertSame(['findArtists:Headhunterz'], $this->musicBrainz->calls);
        self::assertNull($result->artist);
        self::assertSame([$exact->id, $near->id], self::ids($result->candidates));
        $best = $result->candidates[0];
        self::assertInstanceOf(ArtistCandidate::class, $best);
        self::assertSame(100, $best->score);
        self::assertSame('Dutch hardstyle DJ', $best->disambiguation);
        self::assertSame('Person', $best->type);
        self::assertSame('NL', $best->country);
        self::assertSame('1985', $best->lifeSpanBegin);
    }

    /** "F-1 Trillion: Long Bed" with a song per track of the release and Post Malone as its primary artist. */
    private function longBed(): Album
    {
        $album = $this->addAlbum();
        $this->addSongsOf($album, self::release(self::releaseId(1)));
        $this->catalog->creditAlbum($album, $this->catalog->artist(self::ALBUM_ARTIST), ArtistRole::Primary);

        return $album;
    }

    private function search(Album $album, ?string $title = null, ?string $artist = null): MatchCandidates
    {
        return new SearchMatchCandidatesHandler($this->musicBrainz, $this->scorer(), $this->snapshots())(
            new SearchMatchCandidatesQuery(MatchItemType::Album, $album->getPublicId()->toString(), $title, $artist),
        );
    }

    private function searchFailure(Album $album): \Throwable
    {
        try {
            $this->search($album);
        } catch (\Throwable $exception) {
            return $exception;
        }
        self::fail('The search was expected to fail.');
    }

    /**
     * @param list<ReleaseCandidate>|list<RecordingCandidate>|list<ArtistCandidate> $candidates
     *
     * @return list<string>
     */
    private static function ids(array $candidates): array
    {
        return array_column($candidates, 'id');
    }

    private static function recording(int $number, string $title, MusicBrainzArtistCredit $credit, ?int $lengthMs): MusicBrainzRecording
    {
        return new MusicBrainzRecording(
            id: sprintf('5ec0bd00-1111-4000-8000-%012d', $number),
            title: $title,
            score: 100,
            lengthMs: $lengthMs,
            disambiguation: null,
            artistCredit: $credit,
            releases: [new MusicBrainzRecordingRelease(self::releaseId(1), self::ALBUM_TITLE, '2024-08-16', 'XW', 'Official', 27, null)],
        );
    }

    private static function musicBrainzArtist(string $id, string $name, string $disambiguation): MusicBrainzArtist
    {
        return new MusicBrainzArtist($id, $name, 100, $name, 'Person', 'NL', $disambiguation, '1985', null, false);
    }
}
