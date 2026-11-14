<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Service\MusicBrainzMatch;

use App\Catalog\Application\Service\MusicBrainzMatch\CandidateScorer;
use App\Catalog\Application\Service\MusicBrainzMatch\LocalArtist;
use App\Catalog\Application\Service\MusicBrainzMatch\LocalSong;
use App\Catalog\Application\Service\MusicBrainzMatch\TrackPair;
use App\Catalog\Application\Service\MusicBrainzMatch\TrackPairing;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtistCredit;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMediumSummary;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseSummary;
use PHPUnit\Framework\TestCase;

final class CandidateScorerTest extends TestCase
{
    use MusicBrainzReleaseFixtures;

    private const string RELEASE_ID = 'b2b2a7c4-1f63-4a8e-9d5c-6d1c3e0f2a11';

    private CandidateScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new CandidateScorer(new TrackPairing());
    }

    public function testATwoDiscReleaseWhoseLengthsAllFitScoresAtLeast90AndPairsEveryTrack(): void
    {
        $release = self::release(self::RELEASE_ID);

        $scored = $this->scorer->scoreRelease($release, self::album(self::localTracksOf($release, 2.0)));

        self::assertSame($release, $scored->release);
        self::assertGreaterThanOrEqual(90, $scored->score);
        self::assertCount(27, $scored->pairs->pairs);
        self::assertTrue(array_all($scored->pairs->pairs, static fn (TrackPair $pair): bool => $pair->isWithinLengthTolerance()));
    }

    public function testASearchHitWithTheAlbumsTitleArtistAndTrackCountRanksFirst(): void
    {
        $album = self::album(self::localTracksOf(self::release(self::RELEASE_ID)));

        $fitting = $this->scorer->scoreReleaseSummary(self::summary(self::ALBUM_TITLE, 27), $album);
        $otherCount = $this->scorer->scoreReleaseSummary(self::summary(self::ALBUM_TITLE, 26), $album);
        $otherTitle = $this->scorer->scoreReleaseSummary(self::summary('F-1 Trillion', 27), $album);
        $otherArtist = $this->scorer->scoreReleaseSummary(self::summary(self::ALBUM_TITLE, 27, 'Morgan Wallen'), $album);

        self::assertSame(100, $fitting);
        self::assertLessThan($fitting, $otherCount);
        self::assertLessThan($fitting, $otherTitle);
        self::assertLessThan($fitting, $otherArtist);
    }

    public function testASongCandidateFortySecondsOffScoresBelowOneWithinTwoSeconds(): void
    {
        $song = new LocalSong('Pour Me a Drink', ['Post Malone'], 214.0);

        $within = $this->scorer->scoreRecording(self::recording('Pour Me a Drink', self::credit('Post Malone'), 212_000), $song);
        $unknown = $this->scorer->scoreRecording(self::recording('Pour Me a Drink', self::credit('Post Malone'), null), $song);
        $off = $this->scorer->scoreRecording(self::recording('Pour Me a Drink', self::credit('Post Malone'), 254_000), $song);

        self::assertSame(100, $within);
        self::assertLessThan($within, $unknown, 'A length MusicBrainz does not know proves less than one that fits.');
        self::assertLessThan($unknown, $off);
    }

    public function testATagNamingTwoArtistsWithACommaFitsTheFeaturingCredit(): void
    {
        $song = new LocalSong('Missin You Like This', ['Post Malone, Luke Combs'], 200.0);

        $score = $this->scorer->scoreRecording(self::recording('Missin You Like This', self::credit('Post Malone', 'Luke Combs'), 200_000), $song);

        self::assertSame(100, $score);
    }

    public function testTitlesFoldCaseTypographicApostrophesAndAFeaturedArtistSuffix(): void
    {
        $song = new LocalSong("Missin' You Like This (feat. Luke Combs)", ['Post Malone'], 200.0);

        $score = $this->scorer->scoreRecording(self::recording('Missin’ You like This', self::credit('Post Malone', 'Luke Combs'), 200_000), $song);

        self::assertSame(100, $score);
    }

    public function testAnArtistWithTheExactNameAndADisambiguationScoresAboveOneWithASimilarName(): void
    {
        $local = new LocalArtist('Post Malone');

        $exact = $this->scorer->scoreArtist(self::artist('Post Malone', 'US rapper'), $local);
        $similar = $this->scorer->scoreArtist(self::artist('Post Malon', null), $local);

        self::assertSame(100, $exact);
        self::assertLessThan($exact, $similar);
    }

    public function testALocalDisambiguationFavoursTheCandidateThatSharesIt(): void
    {
        $local = new LocalArtist('Nirvana', '90s US grunge band');

        $same = $this->scorer->scoreArtist(self::artist('Nirvana', '90s US grunge band'), $local);
        $other = $this->scorer->scoreArtist(self::artist('Nirvana', '60s UK band'), $local);

        self::assertSame(100, $same);
        self::assertLessThan($same, $other);
    }

    private static function summary(string $title, int $trackCount, string $artist = self::ALBUM_ARTIST): MusicBrainzReleaseSummary
    {
        return new MusicBrainzReleaseSummary(
            id: self::RELEASE_ID,
            title: $title,
            score: 100,
            artistCredit: self::credit($artist),
            date: '2024-08-16',
            country: 'XW',
            status: 'Official',
            disambiguation: null,
            barcode: null,
            labels: [],
            media: [new MusicBrainzMediumSummary('Digital Media', $trackCount)],
            trackCount: $trackCount,
            releaseGroup: null,
        );
    }

    private static function recording(string $title, MusicBrainzArtistCredit $credit, ?int $lengthMs): MusicBrainzRecording
    {
        return new MusicBrainzRecording(
            id: self::recordingId(1, 1),
            title: $title,
            score: 100,
            lengthMs: $lengthMs,
            disambiguation: null,
            artistCredit: $credit,
            releases: [],
        );
    }

    private static function artist(string $name, ?string $disambiguation): MusicBrainzArtist
    {
        return new MusicBrainzArtist(
            id: self::artistId($name . $disambiguation),
            name: $name,
            score: 100,
            sortName: null,
            type: 'Person',
            country: null,
            disambiguation: $disambiguation,
            lifeSpanBegin: null,
            lifeSpanEnd: null,
            ended: false,
        );
    }
}
