<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Service\MusicBrainzMatch;

use App\Catalog\Application\Service\MusicBrainzMatch\CandidateScorer;
use App\Catalog\Application\Service\MusicBrainzMatch\LocalAlbum;
use App\Catalog\Application\Service\MusicBrainzMatch\MatchCandidate;
use App\Catalog\Application\Service\MusicBrainzMatch\MatchConfidence;
use App\Catalog\Application\Service\MusicBrainzMatch\MatchDecision;
use App\Catalog\Application\Service\MusicBrainzMatch\TrackPairing;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtistCredit;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MatchConfidenceTest extends TestCase
{
    use MusicBrainzReleaseFixtures;

    private const string EXPLICIT_ID = 'b2b2a7c4-1f63-4a8e-9d5c-6d1c3e0f2a11';
    private const string CLEAN_ID = '4e8f0c2d-93a1-4b7e-8c55-0a9d6f3b1e22';
    private const string DELUXE_ID = 'd71e9b30-5c4a-4f02-b6e8-27c1a0f49d33';
    private const string ONE_DISC_ID = '1be7f9af-0000-4f0b-9e1d-5a3c7d2e8f44';

    /** The one-disc edition's own recording, at the third track of the second disc (index 20 in track order). */
    private const string ONE_DISC_RECORDING = 'c1ea0000-0000-4000-8000-000000000203';

    /** @var list<string> the tracks, by "<disc>-<position>", on which the clean edition has its own recording */
    private const array CLEAN_RECORDINGS = ['1-2', '1-5', '1-9', '2-3', '2-8'];

    public function testAReleaseThatFitsEveryTrackAndLeadsALongerEditionIsConfident(): void
    {
        $release = self::release(self::EXPLICIT_ID);
        $album = self::album(self::localTracksOf($release));

        $decision = $this->decide($album, $release, self::release(self::DELUXE_ID, [18, 10]));

        self::assertTrue($decision->isConfident());
        self::assertNull($decision->reviewReason);
        self::assertSame(100, $decision->bestScore);
        self::assertSame($release, $decision->match->release);
        self::assertSame([$release], $decision->match->editions);
        self::assertSame(self::recordingIdsOf($release), self::recordingIds($decision->match));
    }

    public function testTwinEditionsCountAsOneConfidentCandidateKeepingOnlyTheRecordingIdsTheyShare(): void
    {
        $explicit = self::release(self::EXPLICIT_ID, date: '2024-08-16');
        $clean = self::release(self::CLEAN_ID, changes: self::cleanRecordings(), date: '2024-08-09');
        $album = self::album(self::localTracksOf($explicit));

        $decision = $this->decide($album, $explicit, $clean);

        self::assertTrue($decision->isConfident());
        self::assertSame(100, $decision->bestScore);
        self::assertSame($clean, $decision->match->release, 'The earliest edition is the release recorded.');
        self::assertSame([$clean, $explicit], $decision->match->editions);
        self::assertSame(self::sharedRecordingIds($explicit), self::recordingIds($decision->match));
    }

    public function testATwinScoringBelowTheBestButWithinTheLeadStillCountsAsAnEdition(): void
    {
        $explicit = self::release(self::EXPLICIT_ID, date: '2024-08-16');
        $clean = self::release(self::CLEAN_ID, self::LONG_BED_DISCS, self::cleanRecordings(), self::ALBUM_TITLE . ' (Clean)', '2024-08-09');
        $album = self::album(self::localTracksOf($explicit));

        $decision = $this->decide($album, $explicit, $clean);

        self::assertTrue($decision->isConfident());
        self::assertSame(100, $decision->bestScore, 'The best-fitting edition gives the score.');
        self::assertSame($clean, $decision->match->release, 'The earliest edition is the release recorded.');
        self::assertSame([$clean, $explicit], $decision->match->editions);
        self::assertSame(self::sharedRecordingIds($explicit), self::recordingIds($decision->match));
    }

    public function testAnEditionThatPutsTheSameTracksOnOneDiscIsATwinAndTheMatchStaysConfident(): void
    {
        $explicit = self::release(self::EXPLICIT_ID, date: '2024-08-16');
        $oneDisc = self::resplit($explicit, self::ONE_DISC_ID, [27], '2024-08-17', [20 => self::ONE_DISC_RECORDING]);
        $album = self::album(self::localTracksOf($explicit));
        $oneDiscScore = new CandidateScorer(new TrackPairing())->scoreRelease($oneDisc, $album)->score;
        self::assertGreaterThan(100 - MatchConfidence::MINIMUM_LEAD, $oneDiscScore, 'The one-disc edition scores within the lead.');

        $decision = $this->decide($album, $explicit, $oneDisc);

        self::assertTrue($decision->isConfident());
        self::assertSame($explicit, $decision->match->release);
        self::assertSame([$explicit, $oneDisc], $decision->match->editions);
        self::assertSame(array_replace(self::recordingIdsOf($explicit), ['2-3' => null]), self::recordingIds($decision->match), 'A recording ID is kept where both editions have it at that place in the track order.');
    }

    public function testAnEarlierTwinThatSplitsItsDiscsOtherwiseIsNotTheReleaseRecorded(): void
    {
        $explicit = self::release(self::EXPLICIT_ID, date: '2024-08-16');
        $oneDisc = self::resplit($explicit, self::ONE_DISC_ID, [27], '2024-08-09');
        $album = self::album(self::localTracksOf($explicit));

        $decision = $this->decide($album, $explicit, $oneDisc);

        self::assertTrue($decision->isConfident());
        self::assertSame($explicit, $decision->match->release, 'The release recorded keeps every song at its own disc and number.');
        self::assertSame([$oneDisc, $explicit], $decision->match->editions, 'Every edition, earliest first.');
        self::assertSame(self::recordingIdsOf($explicit), self::recordingIds($decision->match), 'Each song pairs with its own disc and number.');
    }

    /** @param array<string, array{title?: string, lengthMs?: int|null, artistCredit?: MusicBrainzArtistCredit}> $changes */
    #[DataProvider('editionsThatDisagree')]
    public function testEditionsWhoseTracksDisagreeStayTwoAndNeitherIsConfident(array $changes): void
    {
        $explicit = self::release(self::EXPLICIT_ID);
        $other = self::release(self::CLEAN_ID, changes: $changes + self::cleanRecordings());
        $album = self::album(self::localTracksOf($explicit));

        $decision = $this->decide($album, $explicit, $other);

        self::assertFalse($decision->isConfident());
        self::assertNull($decision->match);
        self::assertSame(AlbumMatchReviewReason::CloseRunnerUp, $decision->reviewReason);
        self::assertSame(100, $decision->bestScore);
    }

    /** @return iterable<string, array{array<string, array{title?: string, lengthMs?: int|null, artistCredit?: MusicBrainzArtistCredit}>}> */
    public static function editionsThatDisagree(): iterable
    {
        yield 'one track title' => [['1-4' => ['title' => 'Track 1-4 (Clean)']]];
        yield 'one track artist credit' => [['1-4' => ['artistCredit' => self::credit(self::ALBUM_ARTIST, 'Blake Shelton')]]];
        yield 'one track length' => [['2-2' => ['lengthMs' => self::lengthMs(19) + 30_000]]];
    }

    public function testATwinScoringFurtherBelowThanTheLeadIsNoEdition(): void
    {
        $explicit = self::release(self::EXPLICIT_ID);
        $twin = self::release(self::CLEAN_ID, changes: self::cleanRecordings(), title: 'Austin');
        $album = self::album(self::localTracksOf($explicit));

        $decision = $this->decide($album, $explicit, $twin);

        self::assertTrue($decision->isConfident());
        self::assertSame([$explicit], $decision->match->editions);
        self::assertSame(self::recordingIdsOf($explicit), self::recordingIds($decision->match));
    }

    public function testAnAlbumOneTrackShortOfTheReleaseNeedsReviewForItsTrackCount(): void
    {
        $release = self::release(self::EXPLICIT_ID);
        $album = self::album(self::localTracksOf($release, missing: ['1-7']));

        $decision = $this->decide($album, $release);

        self::assertNull($decision->match);
        self::assertSame(AlbumMatchReviewReason::TrackCountDiffers, $decision->reviewReason);
        self::assertLessThan(100, $decision->bestScore);
    }

    public function testABestCandidateWithOneTrackFiveSecondsOffIsNotConfident(): void
    {
        $release = self::release(self::EXPLICIT_ID);
        $tracks = self::localTracksOf($release, 0.0);
        $tracks[9] = self::changed($tracks[9], lengthSeconds: $tracks[9]->lengthSeconds + 5.0);

        $decision = $this->decide(self::album($tracks), $release);

        self::assertNull($decision->match);
        self::assertSame(AlbumMatchReviewReason::TrackLengthsDiffer, $decision->reviewReason);
        self::assertGreaterThanOrEqual(MatchConfidence::MINIMUM_SCORE, $decision->bestScore);
    }

    public function testATrackWhoseLengthMusicBrainzDoesNotKnowIsNotConfident(): void
    {
        $release = self::release(self::EXPLICIT_ID, changes: ['2-1' => ['lengthMs' => null]]);
        $album = self::album(self::localTracksOf(self::release(self::EXPLICIT_ID)));

        $decision = $this->decide($album, $release);

        self::assertNull($decision->match);
        self::assertSame(AlbumMatchReviewReason::TrackLengthsDiffer, $decision->reviewReason);
    }

    public function testABestCandidateWhoseTitleFitsPoorlyHasALowScore(): void
    {
        $release = self::release(self::EXPLICIT_ID);
        $album = self::album(self::localTracksOf($release), 'Austin');

        $decision = $this->decide($album, $release);

        self::assertNull($decision->match);
        self::assertSame(AlbumMatchReviewReason::LowScore, $decision->reviewReason);
        self::assertLessThan(MatchConfidence::MINIMUM_SCORE, $decision->bestScore);
    }

    public function testNoCandidatesNeedsReview(): void
    {
        $decision = new MatchConfidence()->decide([]);

        self::assertNull($decision->match);
        self::assertSame(AlbumMatchReviewReason::NoCandidates, $decision->reviewReason);
        self::assertNull($decision->bestScore);
    }

    private function decide(LocalAlbum $album, MusicBrainzRelease ...$releases): MatchDecision
    {
        $scorer = new CandidateScorer(new TrackPairing());
        $releaseList = array_values($releases);

        return new MatchConfidence()->decide(array_map(
            static fn (MusicBrainzRelease $release) => $scorer->scoreRelease($release, $album),
            $releaseList,
        ));
    }

    /** @return array<string, array{recordingId: string}> the clean edition's own recordings */
    private static function cleanRecordings(): array
    {
        return array_map(
            static fn (string $key): array => ['recordingId' => sprintf('c1ea0000-0000-4000-8000-%012s', str_replace('-', '', $key))],
            self::CLEAN_RECORDINGS,
        ) |> (static fn (array $changes): array => array_combine(self::CLEAN_RECORDINGS, $changes));
    }

    /** @return array<string, string|null> the explicit edition's recording IDs, null where the clean edition has its own */
    private static function sharedRecordingIds(MusicBrainzRelease $explicit): array
    {
        $ids = self::recordingIdsOf($explicit);
        foreach (self::CLEAN_RECORDINGS as $key) {
            $ids[$key] = null;
        }
        self::assertCount(22, array_filter($ids));

        return $ids;
    }

    /** @return array<string, string> recording IDs by "<disc>-<position>", in release order */
    private static function recordingIdsOf(MusicBrainzRelease $release): array
    {
        $ids = [];
        foreach ($release->media as $medium) {
            foreach ($medium->tracks as $track) {
                $ids[$medium->position . '-' . $track->position] = $track->recordingId;
            }
        }

        return $ids;
    }

    /** @return array<string, string|null> the recording ID the match writes for each paired track, by "<disc>-<position>" */
    private static function recordingIds(MatchCandidate $match): array
    {
        $ids = [];
        foreach ($match->pairs->pairs as $pair) {
            $ids[$pair->release->disc . '-' . $pair->release->track->position] = $match->recordingId($pair->release);
        }

        return $ids;
    }
}
