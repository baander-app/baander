<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Resource;

use App\Catalog\Application\Query\MusicBrainzMatch\ArtistActionKind;
use App\Catalog\Application\Query\MusicBrainzMatch\CatalogCredit;
use App\Catalog\Application\Query\MusicBrainzMatch\ComparedReleaseTrack;
use App\Catalog\Application\Query\MusicBrainzMatch\ComparedSong;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchArtistAction;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCreditsRow;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchFieldRow;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchTrack;
use App\Catalog\Application\Query\MusicBrainzMatch\PredictedArtist;
use App\Catalog\Application\Query\MusicBrainzMatch\ProposedCredit;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Catalog\Interface\Resource\MatchArtistActionResource;
use App\Catalog\Interface\Resource\MatchComparisonResource;
use App\Catalog\Interface\Resource\MatchFieldRowResource;
use App\Shared\Domain\Model\Uuid;
use OpenApi\Attributes\Schema;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The comparison's data gives every row a key made of the parts an apply takes back (the song,
 * if any, and the field or "credits"), passes current values through as the item holds them, and
 * names artists and the cover by nothing but public values.
 */
final class MatchComparisonResourceTest extends TestCase
{
    private const string ALBUM = 'longBedAlbumPublicId1';
    private const string SONG = 'lukeCombsDuetSongId01';
    private const string LONELY_SONG = 'unpairedLocalSongId01';
    private const string POST_MALONE = 'postMaloneArtistId001';
    private const string DUET_ARTIST = 'postMaloneLukeCombs01';
    private const string RELEASE = '0e1ea5e0-0000-4000-8000-000000000001';
    private const string RECORDING = '5ec0bd00-0000-4000-8000-000100000003';
    private const string LUKE_COMBS_MBID = '2b8c1a5e-0000-4000-8000-000000000003';

    public function testAnAlbumComparisonKeysItsOwnRowsBareAndItsSongsRowsBySong(): void
    {
        $data = MatchComparisonResource::from($this->albumComparison());

        self::assertSame('album', $data['type']);
        self::assertSame(self::ALBUM, $data['publicId']);
        self::assertSame(self::RELEASE, $data['candidateId']);
        self::assertSame(['releaseMbid', 'year', 'genres', 'title'], array_column($data['fields'], 'key'));
        self::assertSame([null, null, null, null], array_column($data['fields'], 'songPublicId'));
        self::assertSame('credits', $data['credits']['key']);
        self::assertNull($data['credits']['songPublicId']);

        [$paired, $unpairedRelease, $unpairedSong] = $data['tracks'];
        self::assertSame([self::SONG . ':mbid', self::SONG . ':title'], array_column($paired['fields'], 'key'));
        self::assertSame([self::SONG, self::SONG], array_column($paired['fields'], 'songPublicId'));
        self::assertSame(['mbid', 'title'], array_column($paired['fields'], 'field'));
        self::assertSame(self::SONG . ':credits', $paired['credits']['key']);
        self::assertSame(self::SONG, $paired['credits']['songPublicId']);
        self::assertNull($unpairedRelease['song']);
        self::assertSame([], $unpairedRelease['fields']);
        self::assertNull($unpairedRelease['credits']);
        self::assertSame(self::LONELY_SONG, $unpairedSong['song']['publicId']);
        self::assertNull($unpairedSong['release']);

        self::assertSame([
            'kind' => 'merge',
            'artistPublicId' => self::DUET_ARTIST,
            'artistName' => 'Post Malone, Luke Combs',
            'target' => ['publicId' => self::POST_MALONE, 'name' => 'Post Malone', 'takesId' => true],
            'requiredRows' => ['credits', self::SONG . ':credits'],
            'ticked' => true,
        ], $data['artistActions'][0]);
    }

    public function testRowsCarryTheValuesTheItemHoldsAndTheirTicks(): void
    {
        $fields = MatchComparisonResource::from($this->albumComparison())['fields'];

        self::assertSame(
            ['key' => 'year', 'field' => 'year', 'songPublicId' => null, 'current' => 2023, 'proposed' => 2024, 'ticked' => false, 'locked' => false, 'changes' => true],
            $fields[1],
            'A field holding another value is shown unticked, with its number as it is.',
        );
        self::assertSame(['current' => null, 'proposed' => ['Country'], 'ticked' => true], array_intersect_key($fields[2], ['current' => 0, 'proposed' => 0, 'ticked' => 0]));
        self::assertSame(['ticked' => false, 'locked' => true, 'changes' => true], array_intersect_key($fields[3], ['ticked' => 0, 'locked' => 0, 'changes' => 0]));
    }

    public function testCreditsNameEachArtistByItsPublicIdAndANewArtistByNone(): void
    {
        $data = MatchComparisonResource::from($this->albumComparison());
        $credits = $data['tracks'][0]['credits'];

        self::assertSame([
            ['artistPublicId' => self::DUET_ARTIST, 'artistName' => 'Post Malone, Luke Combs', 'role' => 'primary', 'creditedName' => 'Post Malone, Luke Combs'],
        ], $credits['current']);
        self::assertSame([
            [
                'role' => 'primary',
                'creditedName' => 'Post Malone, Luke Combs',
                'musicbrainzArtistId' => '9b1a2c3d-0000-4000-8000-000000000001',
                'artist' => ['publicId' => self::POST_MALONE, 'name' => 'Post Malone', 'takesId' => true],
            ],
            [
                'role' => 'featured',
                'creditedName' => 'Luke Combs',
                'musicbrainzArtistId' => self::LUKE_COMBS_MBID,
                'artist' => ['publicId' => null, 'name' => 'Luke Combs', 'takesId' => false],
            ],
        ], $credits['proposed']);
        self::assertTrue($credits['ticked']);
        self::assertTrue($credits['changes']);
    }

    public function testTheDataHoldsNoInternalIdAndShowsTheCoverOnlyAsPresent(): void
    {
        $comparison = $this->albumComparison([MatchFieldRow::compare(MatchField::Cover, true, self::RELEASE, false)]);

        $data = MatchComparisonResource::from($comparison);
        $json = json_encode($data, JSON_THROW_ON_ERROR);

        $cover = array_find($data['fields'], static fn (array $row): bool => $row['field'] === 'cover');
        self::assertSame(['current' => true, 'proposed' => self::RELEASE, 'ticked' => false], array_intersect_key($cover ?? [], ['current' => 0, 'proposed' => 0, 'ticked' => 0]));
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/', $json, 'No version 7 UUID, the kind internal IDs are, is in the data.');
    }

    public function testAnAlbumWithoutACoverShowsNoCurrentCover(): void
    {
        $comparison = $this->albumComparison([MatchFieldRow::compare(MatchField::Cover, null, self::RELEASE, false)]);

        $cover = array_find(MatchComparisonResource::from($comparison)['fields'], static fn (array $row): bool => $row['field'] === 'cover');

        self::assertSame(['current' => null, 'ticked' => true], array_intersect_key($cover ?? [], ['current' => 0, 'ticked' => 0]));
    }

    /** A song's own credits row carries the song's public ID, yet it is the item's own row: its key is bare. */
    public function testASongComparisonKeysItsOwnCreditsBareAndItsActionsNeedThem(): void
    {
        $duetArtist = self::credit(self::DUET_ARTIST, 'Post Malone, Luke Combs', 'primary');
        $comparison = new MatchComparison(
            type: MatchItemType::Song,
            publicId: self::SONG,
            candidateId: self::RECORDING,
            fields: [MatchFieldRow::compare(MatchField::Mbid, null, self::RECORDING, false)],
            credits: MatchCreditsRow::compare(self::SONG, [$duetArtist], [self::postMalone()]),
            artistActions: [new MatchArtistAction(ArtistActionKind::Remove, self::DUET_ARTIST, 'Post Malone, Luke Combs', null, requiredSongs: [self::SONG])],
        );

        $data = MatchComparisonResource::from($comparison);

        self::assertSame(['mbid'], array_column($data['fields'], 'key'));
        self::assertSame('credits', $data['credits']['key']);
        self::assertSame(self::SONG, $data['credits']['songPublicId']);
        self::assertSame([], $data['tracks']);
        self::assertSame(['credits'], $data['artistActions'][0]['requiredRows']);
        self::assertNull($data['artistActions'][0]['target']);
        self::assertSame('remove', $data['artistActions'][0]['kind']);
    }

    public function testAnArtistComparisonHasNoCreditsAndAnUntickedMergeStaysUnticked(): void
    {
        $comparison = new MatchComparison(
            type: MatchItemType::Artist,
            publicId: 'headHunterzArtistId01',
            candidateId: '6c1e0000-0000-4000-8000-000000000001',
            fields: [],
            credits: null,
            artistActions: [new MatchArtistAction(
                ArtistActionKind::Merge,
                'headHunterzArtistId01',
                'HeadHunterz',
                new PredictedArtist(new Uuid(), 'headhunterzArtistId01', 'Headhunterz'),
                ticked: false,
            )],
        );

        $data = MatchComparisonResource::from($comparison);

        self::assertNull($data['credits']);
        self::assertSame([], $data['artistActions'][0]['requiredRows']);
        self::assertFalse($data['artistActions'][0]['ticked']);
        self::assertSame(['publicId' => 'headhunterzArtistId01', 'name' => 'Headhunterz', 'takesId' => false], $data['artistActions'][0]['target']);
    }

    /** The schemas the web client is generated from name every field, item type and action kind the data can hold. */
    public function testTheSchemasListEveryValueTheDataCanHold(): void
    {
        self::assertSame(array_column(MatchField::cases(), 'value'), self::enum(MatchFieldRowResource::class, 'field'));
        self::assertSame(array_column(MatchItemType::cases(), 'value'), self::enum(MatchComparisonResource::class, 'type'));
        self::assertSame(array_column(ArtistActionKind::cases(), 'value'), self::enum(MatchArtistActionResource::class, 'kind'));
    }

    /**
     * @param class-string $resource
     *
     * @return list<mixed>
     */
    private static function enum(string $resource, string $property): array
    {
        $schema = new ReflectionClass($resource)->getAttributes(Schema::class)[0]->newInstance();
        $enum = array_find($schema->properties, static fn (object $candidate): bool => $candidate->property === $property)?->enum;
        self::assertIsArray($enum, sprintf('%s lists the values of %s.', $resource, $property));

        return $enum;
    }

    /** @param list<MatchFieldRow> $extraFields */
    private function albumComparison(array $extraFields = []): MatchComparison
    {
        $duetArtist = self::credit(self::DUET_ARTIST, 'Post Malone, Luke Combs', 'primary');
        $lukeCombs = new ProposedCredit(ArtistRole::Featured, 'Luke Combs', self::LUKE_COMBS_MBID, PredictedArtist::created('Luke Combs'));
        $duetPrimary = new ProposedCredit(ArtistRole::Primary, 'Post Malone, Luke Combs', '9b1a2c3d-0000-4000-8000-000000000001', self::postMaloneArtist());
        $title = MatchFieldRow::compare(MatchField::Title, 'Long Bed', 'F-1 Trillion: Long Bed', true);

        return new MatchComparison(
            type: MatchItemType::Album,
            publicId: self::ALBUM,
            candidateId: self::RELEASE,
            fields: [
                MatchFieldRow::compare(MatchField::ReleaseMbid, null, self::RELEASE, false),
                MatchFieldRow::compare(MatchField::Year, 2023, 2024, false),
                MatchFieldRow::compare(MatchField::Genres, [], ['Country'], false),
                $title,
                ...$extraFields,
            ],
            credits: MatchCreditsRow::compare(null, [$duetArtist], [self::postMalone()]),
            tracks: [
                new MatchTrack(
                    song: new ComparedSong(self::SONG, 'Pour Me a Drink', 1, 3, 151.5),
                    release: new ComparedReleaseTrack(1, 3, '3', 'Pour Me a Drink', 150_000, 'Post Malone feat. Luke Combs', self::RECORDING),
                    fields: [
                        MatchFieldRow::compare(MatchField::Mbid, null, self::RECORDING, false),
                        MatchFieldRow::compare(MatchField::Title, 'Pour me a drink', 'Pour Me a Drink', false),
                    ],
                    credits: MatchCreditsRow::compare(self::SONG, [$duetArtist], [$duetPrimary, $lukeCombs]),
                ),
                new MatchTrack(null, new ComparedReleaseTrack(2, 10, '10', 'Bonus Track', null, 'Post Malone', null)),
                new MatchTrack(new ComparedSong(self::LONELY_SONG, 'Voice Memo', null, null, 33.0), null),
            ],
            artistActions: [new MatchArtistAction(
                kind: ArtistActionKind::Merge,
                artistPublicId: self::DUET_ARTIST,
                artistName: 'Post Malone, Luke Combs',
                target: self::postMaloneArtist(),
                requiredSongs: [self::SONG],
                requiresAlbumCredits: true,
            )],
        );
    }

    private static function postMalone(): ProposedCredit
    {
        return new ProposedCredit(ArtistRole::Primary, 'Post Malone', '9b1a2c3d-0000-4000-8000-000000000001', self::postMaloneArtist());
    }

    private static function postMaloneArtist(): PredictedArtist
    {
        return new PredictedArtist(new Uuid(), self::POST_MALONE, 'Post Malone', takesId: true);
    }

    private static function credit(string $publicId, string $name, string $role): CatalogCredit
    {
        return new CatalogCredit(new Uuid(), $publicId, $name, $role, $name);
    }
}
