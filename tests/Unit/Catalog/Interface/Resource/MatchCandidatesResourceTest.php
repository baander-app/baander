<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\ArtistCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCandidates;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Query\MusicBrainzMatch\RecordingCandidate;
use App\Catalog\Application\Query\MusicBrainzMatch\RecordingCandidateRelease;
use App\Catalog\Application\Query\MusicBrainzMatch\ReleaseCandidate;
use App\Catalog\Interface\Resource\MatchCandidatesResource;
use PHPUnit\Framework\TestCase;

/** The candidates' data names what was searched and lists each candidate with what tells it from the others. */
final class MatchCandidatesResourceTest extends TestCase
{
    public function testAnAlbumSearchListsReleasesWithTheirEditionDetails(): void
    {
        $release = new ReleaseCandidate(
            id: '0e1ea5e0-0000-4000-8000-000000000001',
            score: 97,
            title: 'F-1 Trillion: Long Bed',
            artistCredit: 'Post Malone',
            date: '2024-08-16',
            country: 'XW',
            status: 'Official',
            disambiguation: 'explicit',
            barcode: '602475920000',
            labels: ['Mercury Records'],
            catalogNumbers: ['B0040000-02'],
            formats: ['Digital Media'],
            trackCount: 27,
            mediumCount: 2,
            releaseGroupId: 'a1b0f000-0000-4000-8000-000000000001',
            releaseGroupType: 'Album',
            hasFrontCover: true,
        );

        $data = MatchCandidatesResource::from(new MatchCandidates(MatchItemType::Album, 'longBedAlbumPublicId1', 'F-1 Trillion: Long Bed', 'Post Malone', [$release]));

        self::assertSame([
            'type' => 'album',
            'publicId' => 'longBedAlbumPublicId1',
            'title' => 'F-1 Trillion: Long Bed',
            'artist' => 'Post Malone',
            'candidates' => [[
                'id' => '0e1ea5e0-0000-4000-8000-000000000001',
                'score' => 97,
                'title' => 'F-1 Trillion: Long Bed',
                'artistCredit' => 'Post Malone',
                'date' => '2024-08-16',
                'country' => 'XW',
                'status' => 'Official',
                'disambiguation' => 'explicit',
                'barcode' => '602475920000',
                'labels' => ['Mercury Records'],
                'catalogNumbers' => ['B0040000-02'],
                'formats' => ['Digital Media'],
                'trackCount' => 27,
                'mediumCount' => 2,
                'releaseGroupId' => 'a1b0f000-0000-4000-8000-000000000001',
                'releaseGroupType' => 'Album',
                'hasFrontCover' => true,
            ]],
        ], $data);
    }

    public function testASongSearchListsRecordingsWithTheReleasesTheyAppearOn(): void
    {
        $recording = new RecordingCandidate(
            id: '5ec0bd00-0000-4000-8000-000100000003',
            score: 88,
            title: 'Pour Me a Drink',
            artistCredit: 'Post Malone feat. Blake Shelton',
            lengthMs: 211_000,
            disambiguation: null,
            releases: [new RecordingCandidateRelease('0e1ea5e0-0000-4000-8000-000000000001', 'F-1 Trillion', '2024-08-16', 'XW', 'Official', 18)],
        );

        $data = MatchCandidatesResource::from(new MatchCandidates(MatchItemType::Song, 'pourMeADrinkSongId001', 'Pour Me a Drink', 'Post Malone', [$recording]));

        self::assertSame('song', $data['type']);
        self::assertSame([[
            'id' => '5ec0bd00-0000-4000-8000-000100000003',
            'score' => 88,
            'title' => 'Pour Me a Drink',
            'artistCredit' => 'Post Malone feat. Blake Shelton',
            'lengthMs' => 211_000,
            'disambiguation' => null,
            'releases' => [[
                'id' => '0e1ea5e0-0000-4000-8000-000000000001',
                'title' => 'F-1 Trillion',
                'date' => '2024-08-16',
                'country' => 'XW',
                'status' => 'Official',
                'trackCount' => 18,
            ]],
        ]], $data['candidates']);
    }

    public function testAnArtistSearchNamesNoArtistAndListsArtistsWithTheirActiveYears(): void
    {
        $artist = new ArtistCandidate('6c1e0000-0000-4000-8000-000000000001', 100, 'Headhunterz', 'Headhunterz', 'Dutch hardstyle DJ', 'Person', 'NL', '1985-09-12', null, false);

        $data = MatchCandidatesResource::from(new MatchCandidates(MatchItemType::Artist, 'headHunterzArtistId01', 'Headhunterz', null, [$artist]));

        self::assertSame(['type' => 'artist', 'title' => 'Headhunterz', 'artist' => null], array_intersect_key($data, ['type' => 0, 'title' => 0, 'artist' => 0]));
        self::assertSame([[
            'id' => '6c1e0000-0000-4000-8000-000000000001',
            'score' => 100,
            'name' => 'Headhunterz',
            'sortName' => 'Headhunterz',
            'disambiguation' => 'Dutch hardstyle DJ',
            'type' => 'Person',
            'country' => 'NL',
            'lifeSpanBegin' => '1985-09-12',
            'lifeSpanEnd' => null,
            'ended' => false,
        ]], $data['candidates']);
    }

    public function testASearchWithoutCandidatesListsNone(): void
    {
        $data = MatchCandidatesResource::from(new MatchCandidates(MatchItemType::Album, 'unknownDemosAlbumId01', 'Unknown Demos', null, []));

        self::assertSame([], $data['candidates']);
    }
}
