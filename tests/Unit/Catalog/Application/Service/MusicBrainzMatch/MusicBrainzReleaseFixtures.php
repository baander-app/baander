<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Service\MusicBrainzMatch;

use App\Catalog\Application\Service\MusicBrainzMatch\LocalAlbum;
use App\Catalog\Application\Service\MusicBrainzMatch\LocalTrack;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtistCredit;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCreditedArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMedium;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzTrack;

/**
 * Releases shaped like "F-1 Trillion: Long Bed" (18 tracks on the first disc, 9 on the second) and
 * the local files that fit them.
 */
trait MusicBrainzReleaseFixtures
{
    private const string ALBUM_TITLE = 'F-1 Trillion: Long Bed';
    private const string ALBUM_ARTIST = 'Post Malone';

    /** @var list<int> tracks per disc */
    private const array LONG_BED_DISCS = [18, 9];

    /**
     * A looked-up release whose tracks are titled "Track <disc>-<position>", credited to Post Malone
     * and spaced seven seconds apart in length, from 2:30 on.
     *
     * @param list<int> $discs tracks per disc
     * @param array<string, array{title?: string, lengthMs?: int|null, recordingId?: string, artistCredit?: MusicBrainzArtistCredit}> $changes by "<disc>-<position>"
     */
    private static function release(
        string $id,
        array $discs = self::LONG_BED_DISCS,
        array $changes = [],
        string $title = self::ALBUM_TITLE,
        ?string $date = '2024-08-16',
    ): MusicBrainzRelease {
        $media = [];
        $index = 0;
        foreach ($discs as $discIndex => $trackCount) {
            $disc = $discIndex + 1;
            $tracks = [];
            for ($position = 1; $position <= $trackCount; ++$position) {
                $change = $changes[$disc . '-' . $position] ?? [];
                $tracks[] = new MusicBrainzTrack(
                    id: sprintf('7a1c0de0-0000-4000-8000-%04d%08d', $disc, $position),
                    position: $position,
                    number: (string) $position,
                    title: $change['title'] ?? sprintf('Track %d-%d', $disc, $position),
                    lengthMs: array_key_exists('lengthMs', $change) ? $change['lengthMs'] : self::lengthMs($index),
                    recordingId: $change['recordingId'] ?? self::recordingId($disc, $position),
                    artistCredit: $change['artistCredit'] ?? self::credit(self::ALBUM_ARTIST),
                );
                ++$index;
            }
            $media[] = new MusicBrainzMedium($disc, 'Digital Media', null, $tracks);
        }

        return new MusicBrainzRelease(
            id: $id,
            title: $title,
            artistCredit: self::credit(self::ALBUM_ARTIST),
            date: $date,
            country: 'XW',
            status: 'Official',
            disambiguation: null,
            barcode: null,
            labels: [],
            media: $media,
            releaseGroup: null,
            genres: [],
            hasFrontCover: false,
        );
    }

    /**
     * An edition with the same tracks as `$release` in the same order, split into discs as `$discs`
     * says, as MusicBrainz lists an edition that puts both discs of an album on one medium.
     *
     * @param list<int>          $discs      tracks per disc
     * @param array<int, string> $recordings recording IDs of the edition's own, by index in the track order
     */
    private static function resplit(MusicBrainzRelease $release, string $id, array $discs, ?string $date, array $recordings = []): MusicBrainzRelease
    {
        $tracks = $release->tracks();
        $media = [];
        $offset = 0;
        foreach ($discs as $discIndex => $trackCount) {
            $disc = $discIndex + 1;
            $media[] = new MusicBrainzMedium($disc, 'Digital Media', null, array_map(
                static fn (MusicBrainzTrack $track, int $index): MusicBrainzTrack => new MusicBrainzTrack(
                    id: sprintf('7a1c0de1-0000-4000-8000-%04d%08d', $disc, $index + 1),
                    position: $index + 1,
                    number: (string) ($index + 1),
                    title: $track->title,
                    lengthMs: $track->lengthMs,
                    recordingId: $recordings[$offset + $index] ?? $track->recordingId,
                    artistCredit: $track->artistCredit,
                ),
                array_slice($tracks, $offset, $trackCount),
                range(0, $trackCount - 1),
            ));
            $offset += $trackCount;
        }

        return new MusicBrainzRelease(
            id: $id,
            title: $release->title,
            artistCredit: $release->artistCredit,
            date: $date,
            country: 'JP',
            status: $release->status,
            disambiguation: $release->disambiguation,
            barcode: $release->barcode,
            labels: $release->labels,
            media: $media,
            releaseGroup: $release->releaseGroup,
            genres: $release->genres,
            hasFrontCover: $release->hasFrontCover,
        );
    }

    private static function lengthMs(int $index): int
    {
        return 150_000 + 7_000 * $index;
    }

    private static function recordingId(int $disc, int $position): string
    {
        return sprintf('5ec0bd00-0000-4000-8000-%04d%08d', $disc, $position);
    }

    /** A credit naming each artist in turn, joined by " feat. ". */
    private static function credit(string ...$names): MusicBrainzArtistCredit
    {
        $last = array_key_last($names);

        return new MusicBrainzArtistCredit(array_map(
            static fn (int $index, string $name): MusicBrainzCreditedArtist => new MusicBrainzCreditedArtist(
                artistId: self::artistId($name),
                name: $name,
                joinPhrase: $index === $last ? '' : ' feat. ',
                artistName: $name,
                artistSortName: null,
            ),
            array_keys($names),
            $names,
        ));
    }

    /** A UUID-shaped MusicBrainz ID that stays the same for one name. */
    private static function artistId(string $name): string
    {
        $hash = md5($name);

        return sprintf('%s-%s-4%s-8%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3), substr($hash, 17, 3), substr($hash, 20, 12));
    }

    /**
     * One local file per track of the release, tagged with its disc, number and title and
     * `$offsetSeconds` longer than the release says.
     *
     * @param list<string> $missing "<disc>-<position>" of tracks the album has no file for
     *
     * @return list<LocalTrack>
     */
    private static function localTracksOf(MusicBrainzRelease $release, float $offsetSeconds = 1.5, array $missing = []): array
    {
        $tracks = [];
        foreach ($release->media as $medium) {
            foreach ($medium->tracks as $track) {
                $key = $medium->position . '-' . $track->position;
                if (in_array($key, $missing, true)) {
                    continue;
                }
                $tracks[] = new LocalTrack(
                    id: 'song-' . $key,
                    title: $track->title,
                    disc: $medium->position,
                    position: $track->position,
                    lengthSeconds: $track->lengthMs / 1000 + $offsetSeconds,
                    artistNames: [self::ALBUM_ARTIST],
                );
            }
        }

        return $tracks;
    }

    /** The track with the named values replaced, such as `lengthSeconds: 200.0`. */
    private static function changed(LocalTrack $track, mixed ...$changes): LocalTrack
    {
        return new LocalTrack(...[...get_object_vars($track), ...$changes]);
    }

    /** @param list<LocalTrack> $tracks */
    private static function album(array $tracks, string $title = self::ALBUM_TITLE): LocalAlbum
    {
        return new LocalAlbum($title, self::ALBUM_ARTIST, $tracks);
    }
}
