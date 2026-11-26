<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Service\MusicBrainzMatch;

use App\Catalog\Application\Service\MusicBrainzMatch\LocalTrack;
use App\Catalog\Application\Service\MusicBrainzMatch\ReleaseTrack;
use App\Catalog\Application\Service\MusicBrainzMatch\TrackPair;
use App\Catalog\Application\Service\MusicBrainzMatch\TrackPairing;
use PHPUnit\Framework\TestCase;

final class TrackPairingTest extends TestCase
{
    use MusicBrainzReleaseFixtures;

    private const string RELEASE_ID = 'b2b2a7c4-1f63-4a8e-9d5c-6d1c3e0f2a11';

    public function testEveryTrackOfATwoDiscReleasePairsByDiscAndPosition(): void
    {
        $release = self::release(self::RELEASE_ID);
        $local = self::localTracksOf($release) |> array_reverse(...);

        $pairs = new TrackPairing()->pair($local, $release);

        self::assertCount(27, $pairs->pairs);
        self::assertSame([], $pairs->unpairedLocal);
        self::assertSame([], $pairs->unpairedRelease);
        self::assertSame(
            array_map(static fn (TrackPair $pair): string => $pair->local->id, $pairs->pairs),
            array_map(static fn (TrackPair $pair): string => sprintf('song-%d-%d', $pair->release->disc, $pair->release->track->position), $pairs->pairs),
        );
        self::assertSame(['1-1', '2-9'], [self::key(array_first($pairs->pairs)->release), self::key(array_last($pairs->pairs)->release)], 'Pairs follow the release order.');
    }

    public function testTheReleaseTrackAnAlbumHasNoFileForStaysUnpaired(): void
    {
        $release = self::release(self::RELEASE_ID);

        $pairs = new TrackPairing()->pair(self::localTracksOf($release, missing: ['1-7']), $release);

        self::assertCount(26, $pairs->pairs);
        self::assertSame([], $pairs->unpairedLocal);
        self::assertSame(['1-7'], array_map(self::key(...), $pairs->unpairedRelease));
    }

    public function testADiscAndPositionPairHoldsWhateverTheLengths(): void
    {
        $release = self::release(self::RELEASE_ID, [3]);
        $local = self::localTracksOf($release);
        $local[2] = self::changed($local[2], lengthSeconds: $local[2]->lengthSeconds + 60);

        $pair = new TrackPairing()->pair($local, $release)->pairs[2];

        self::assertSame(['song-1-3', '1-3'], [$pair->local->id, self::key($pair->release)]);
        self::assertEqualsWithDelta(61.5, $pair->lengthDifferenceSeconds(), 0.001);
        self::assertFalse($pair->isWithinLengthTolerance());
    }

    public function testATrackWithoutADiscTagPairsOnTheFirstDisc(): void
    {
        $release = self::release(self::RELEASE_ID, [3, 2]);
        $local = array_map(
            static fn (LocalTrack $track): LocalTrack => self::changed($track, disc: null, lengthSeconds: $track->lengthSeconds + 20),
            array_slice(self::localTracksOf($release), 0, 3),
        );

        $pairs = new TrackPairing()->pair($local, $release);

        self::assertSame(['1-1', '1-2', '1-3'], array_map(static fn (TrackPair $pair): string => self::key($pair->release), $pairs->pairs));
        self::assertSame(['song-1-1', 'song-1-2', 'song-1-3'], array_map(static fn (TrackPair $pair): string => $pair->local->id, $pairs->pairs));
        self::assertSame(['2-1', '2-2'], array_map(self::key(...), $pairs->unpairedRelease));
    }

    public function testTracksWithoutAPositionPairByLengthWithinTheTolerance(): void
    {
        $release = self::release(self::RELEASE_ID, [4]);
        $local = array_map(static fn (LocalTrack $track): LocalTrack => self::changed($track, position: null), self::localTracksOf($release, 2.5));
        $local[3] = self::changed($local[3], lengthSeconds: $local[3]->lengthSeconds + 2.0);

        $pairs = new TrackPairing()->pair($local, $release);

        self::assertSame(['song-1-1', 'song-1-2', 'song-1-3'], array_map(static fn (TrackPair $pair): string => $pair->local->id, $pairs->pairs));
        self::assertSame(['1-1', '1-2', '1-3'], array_map(static fn (TrackPair $pair): string => self::key($pair->release), $pairs->pairs));
        self::assertSame(['song-1-4'], array_map(static fn (LocalTrack $track): string => $track->id, $pairs->unpairedLocal), 'Four and a half seconds is beyond the tolerance.');
        self::assertSame(['1-4'], array_map(self::key(...), $pairs->unpairedRelease));
    }

    public function testALengthThatFitsTwoFreeReleaseTracksIsNotGuessed(): void
    {
        $release = self::release(self::RELEASE_ID, [2], ['1-1' => ['lengthMs' => 200_000], '1-2' => ['lengthMs' => 202_000]]);
        $local = [new LocalTrack(id: 'song-untagged', title: 'Track', disc: null, position: null, lengthSeconds: 201.0)];

        $pairs = new TrackPairing()->pair($local, $release);

        self::assertSame([], $pairs->pairs);
        self::assertSame($local, $pairs->unpairedLocal);
        self::assertSame(['1-1', '1-2'], array_map(self::key(...), $pairs->unpairedRelease));
    }

    public function testTracksSharingOneDiscAndPositionPairByLength(): void
    {
        $release = self::release(self::RELEASE_ID, [3]);
        [$first, $second, $third] = self::localTracksOf($release);
        $local = [$first, self::changed($third, position: 2), $second];

        $pairs = new TrackPairing()->pair($local, $release);

        $pairRows = array_map(static fn (TrackPair $pair): array => [$pair->local->id, self::key($pair->release)], $pairs->pairs);
        self::assertSame(
            ['song-1-1' => '1-1', 'song-1-2' => '1-2', 'song-1-3' => '1-3'],
            array_column($pairRows, 1, 0),
        );
    }

    private static function key(ReleaseTrack $track): string
    {
        return $track->disc . '-' . $track->track->position;
    }
}
