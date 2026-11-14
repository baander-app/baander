<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch;

use App\Catalog\Application\Query\MusicBrainzMatch\ArtistActionKind;
use App\Catalog\Application\Query\MusicBrainzMatch\CompareMatchQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchArtistAction;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchFieldRow;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchTrack;
use App\Catalog\Application\Query\MusicBrainzMatch\ProposedCredit;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\CompareMatchHandler;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchComparisonBuilder;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseGroup;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class CompareMatchHandlerTest extends TestCase
{
    use MatchQueryFixtures;

    /** The guest on each of the album's duets, by its track on the first disc. */
    private const array GUESTS = [
        1 => 'Morgan Wallen', 2 => 'Blake Shelton', 3 => 'Luke Combs', 4 => 'Lainey Wilson',
        5 => 'Hank Williams Jr.', 6 => 'Chris Stapleton', 7 => 'Jelly Roll', 8 => 'Dolly Parton',
        9 => 'Tim McGraw', 10 => 'Brad Paisley', 11 => 'Sierra Ferrell', 12 => 'Josh Turner',
        13 => 'Hardy', 14 => 'Tyler Childers',
    ];

    protected function setUp(): void
    {
        $this->setUpLibrary();
    }

    public function testAYearThatDiffersStaysUntickedWhileAnEmptyGenreIsTicked(): void
    {
        $album = $this->addAlbum(year: 2023);
        $release = self::releaseWith(
            self::release(self::releaseId(1)),
            date: '2024-03-01',
            releaseGroup: new MusicBrainzReleaseGroup('a1b0f000-0000-4000-8000-000000000001', self::ALBUM_TITLE, 'Album', '2024-03-01', ['Country']),
        );
        $this->addSongsOf($album, $release);

        $comparison = $this->compareAlbum($album, $release);

        self::assertRow(MatchField::Year, 2023, 2024, ticked: false, row: $comparison->field(MatchField::Year));
        self::assertRow(MatchField::Genres, null, ['Country'], ticked: true, row: $comparison->field(MatchField::Genres));
        self::assertRow(MatchField::ReleaseMbid, null, $release->id, ticked: true, row: $comparison->field(MatchField::ReleaseMbid));
        self::assertRow(MatchField::Mbid, null, 'a1b0f000-0000-4000-8000-000000000001', ticked: true, row: $comparison->field(MatchField::Mbid));
        self::assertNull($comparison->field(MatchField::Label), 'MusicBrainz gives no label, so there is no row.');
    }

    /** An image's ID is internal: the cover row shows only whether the album has a cover. */
    public function testTheCoverRowShowsWhetherTheAlbumHasACover(): void
    {
        $release = self::releaseWith(self::release(self::releaseId(1)), hasFrontCover: true);
        $bare = $this->addAlbum('Bare');
        $covered = $this->addAlbum('Covered');
        $covered->setCoverImage(new Uuid());

        self::assertRow(MatchField::Cover, null, $release->id, ticked: true, row: $this->compareAlbum($bare, $release)->field(MatchField::Cover));
        self::assertRow(MatchField::Cover, true, $release->id, ticked: false, row: $this->compareAlbum($covered, $release)->field(MatchField::Cover));
    }

    public function testALockedTitleShowsTheMusicBrainzTitleButCannotBeTicked(): void
    {
        $album = $this->addAlbum('F-1 Trillion Long Bed');
        $album->lockField('title');
        $release = self::release(self::releaseId(1));
        $this->addSongsOf($album, $release);

        $title = $this->compareAlbum($album, $release)->field(MatchField::Title);

        self::assertNotNull($title);
        self::assertSame('F-1 Trillion Long Bed', $title->current);
        self::assertSame(self::ALBUM_TITLE, $title->proposed);
        self::assertTrue($title->locked);
        self::assertFalse($title->ticked);
        self::assertFalse($title->isSelectable());
    }

    public function testTheLongBedComparisonCreditsEachDuetToPostMaloneAndItsGuestAndMergesEachPostMaloneAndGuestArtist(): void
    {
        $album = $this->addAlbum();
        $changes = [];
        foreach (self::GUESTS as $position => $guest) {
            $changes['1-' . $position] = ['artistCredit' => self::credit(self::ALBUM_ARTIST, $guest)];
        }
        $release = self::release(self::releaseId(1), changes: $changes);
        $songs = $this->addSongsOf($album, $release);
        $postMalone = $this->catalog->artist(self::ALBUM_ARTIST);
        $this->catalog->creditAlbum($album, $postMalone, ArtistRole::Primary);
        $duetArtists = [];
        foreach ($songs as $song) {
            $guest = $song->getDisc() === 1 ? self::GUESTS[$song->getTrack()] ?? null : null;
            if ($guest === null) {
                $this->catalog->creditSong($song, $postMalone, ArtistRole::Primary);
                continue;
            }
            $duetArtists[$song->getPublicId()->toString()] = $duetArtist = $this->catalog->artist(self::ALBUM_ARTIST . ', ' . $guest);
            $this->catalog->creditSong($song, $duetArtist, ArtistRole::Primary);
        }

        $comparison = $this->compareAlbum($album, $release);

        self::assertCount(27, $comparison->tracks);
        self::assertTrue(array_all($comparison->tracks, static fn (MatchTrack $track): bool => $track->isPaired()));
        foreach ($comparison->tracks as $track) {
            $credits = $track->credits;
            self::assertNotNull($credits);
            self::assertTrue($credits->ticked, 'Credits are ticked by default.');
            $primary = $credits->proposed[0];
            self::assertSame(ArtistRole::Primary, $primary->role);
            self::assertSame($postMalone->getPublicId()->toString(), $primary->artist->publicId, 'Post Malone takes the primary credit.');
            self::assertTrue($primary->artist->takesId, 'Post Malone takes on its MusicBrainz ID rather than a second Post Malone being made.');
            $duetArtist = $duetArtists[$track->song?->publicId] ?? null;
            if ($duetArtist === null) {
                self::assertCount(1, $credits->proposed);
                self::assertSame(self::ALBUM_ARTIST, $primary->creditedName);
                continue;
            }
            $guest = self::GUESTS[$track->song?->track];
            self::assertSame($duetArtist->getName(), $primary->creditedName, 'The song keeps showing its tagged artist text.');
            self::assertCount(2, $credits->proposed);
            $featured = $credits->proposed[1];
            self::assertSame(ArtistRole::Featured, $featured->role);
            self::assertSame($guest, $featured->creditedName);
            self::assertTrue($featured->artist->isNew());
            self::assertSame($guest, $featured->artist->name);
        }

        self::assertCount(14, $comparison->artistActions);
        foreach ($comparison->artistActions as $action) {
            $songPublicId = array_search($action->artistPublicId, array_map(static fn (Artist $artist): string => $artist->getPublicId()->toString(), $duetArtists), true);
            self::assertIsString($songPublicId, 'Each action is a "Post Malone, X" artist.');
            self::assertSame(ArtistActionKind::Merge, $action->kind);
            self::assertSame($postMalone->getPublicId()->toString(), $action->target?->publicId, 'It merges into Post Malone.');
            self::assertTrue($action->ticked);
            self::assertSame([$songPublicId], $action->requiredSongs, 'Unticking that duet\'s credits keeps the artist.');
            self::assertFalse($action->requiresAlbumCredits);
        }
    }

    public function testAnArtistIsMergedOnlyWhileEveryCreditRowNamingItIsTickedAndIsKeptWhenCreditedElsewhere(): void
    {
        $album = $this->addAlbum();
        $release = self::release(self::releaseId(1), [4], changes: [
            '1-1' => ['artistCredit' => self::credit(self::ALBUM_ARTIST, 'Luke Combs')],
            '1-2' => ['artistCredit' => self::credit(self::ALBUM_ARTIST, 'Luke Combs')],
            '1-3' => ['artistCredit' => self::credit(self::ALBUM_ARTIST, 'Morgan Wallen')],
        ]);
        [$first, $second, $third, $fourth] = $this->addSongsOf($album, $release);
        $postMalone = $this->catalog->artist(self::ALBUM_ARTIST);
        $withLukeCombs = $this->catalog->artist('Post Malone, Luke Combs');
        $withMorganWallen = $this->catalog->artist('Post Malone, Morgan Wallen');
        $remixer = $this->catalog->artist('Mystery Guest');
        $this->catalog->creditSong($first, $withLukeCombs, ArtistRole::Primary);
        $this->catalog->creditSong($second, $withLukeCombs, ArtistRole::Primary);
        $this->catalog->creditSong($third, $withMorganWallen, ArtistRole::Primary);
        $this->catalog->creditSong($fourth, $postMalone, ArtistRole::Primary);
        $this->catalog->creditSong($fourth, $remixer, ArtistRole::Featured);
        $this->catalog->creditedElsewhere = [$withMorganWallen->getId()->toString()];

        $actions = $this->compareAlbum($album, $release)->artistActions;

        self::assertSame([$withLukeCombs->getPublicId()->toString(), $remixer->getPublicId()->toString()], array_column($actions, 'artistPublicId'));
        [$merge, $removal] = $actions;
        self::assertSame(ArtistActionKind::Merge, $merge->kind);
        self::assertSame([$first->getPublicId()->toString(), $second->getPublicId()->toString()], $merge->requiredSongs);
        self::assertSame(ArtistActionKind::Remove, $removal->kind, 'A featured-only artist has no primary credits to hand over.');
        self::assertNull($removal->target);
        self::assertSame([$fourth->getPublicId()->toString()], $removal->requiredSongs);
    }

    public function testTheReleasesExtraTrackStaysUnpairedAndHasNoRows(): void
    {
        $album = $this->addAlbum();
        $release = self::release(self::releaseId(1));
        $this->addSongsOf($album, $release, missing: ['2-9']);

        $tracks = $this->compareAlbum($album, $release)->tracks;

        self::assertCount(27, $tracks);
        $unpaired = array_filter($tracks, static fn (MatchTrack $track): bool => !$track->isPaired()) |> array_values(...);
        self::assertCount(1, $unpaired);
        self::assertNull($unpaired[0]->song);
        self::assertSame([2, 9], [$unpaired[0]->release?->disc, $unpaired[0]->release?->position]);
        self::assertSame([], $unpaired[0]->fields);
        self::assertNull($unpaired[0]->credits);
        $paired = $tracks[0];
        self::assertSame(
            [MatchField::Mbid, MatchField::Title, MatchField::Track, MatchField::Disc, MatchField::Year],
            array_column($paired->fields, 'field'),
        );
        self::assertSame(self::recordingId(1, 1), $paired->release?->recordingId);
    }

    public function testASongComparisonOffersOnlyThatSongsFieldsAndNeverAnotherAlbum(): void
    {
        $album = $this->addAlbum();
        $song = $this->addSongsOf($album, self::release(self::releaseId(1), [4]))[3];
        $duetArtist = $this->catalog->artist('Post Malone, Luke Combs');
        $postMalone = $this->catalog->artist(self::ALBUM_ARTIST);
        $this->catalog->creditSong($song, $duetArtist, ArtistRole::Primary);
        $recording = new MusicBrainzRecording(
            id: '5ec0bd00-2222-4000-8000-000000000004',
            title: 'Track 1-4',
            score: 0,
            lengthMs: 171_000,
            disambiguation: null,
            artistCredit: self::credit(self::ALBUM_ARTIST, 'Luke Combs'),
            releases: [],
        );
        $this->musicBrainz->recordings[$recording->id] = $recording;

        $comparison = $this->handler()(new CompareMatchQuery(MatchItemType::Song, $song->getPublicId()->toString(), $recording->id));

        self::assertSame(['fetchRecording:' . $recording->id], $this->musicBrainz->calls);
        self::assertSame([MatchField::Mbid, MatchField::Title], array_column($comparison->fields, 'field'), 'No album, track, disc or release field is offered.');
        self::assertSame([], $comparison->tracks);
        self::assertRow(MatchField::Mbid, null, $recording->id, ticked: true, row: $comparison->field(MatchField::Mbid));
        self::assertRow(MatchField::Title, 'Track 1-4', 'Track 1-4', ticked: false, row: $comparison->field(MatchField::Title));
        self::assertFalse($comparison->field(MatchField::Title)?->isSelectable(), 'An unchanged field cannot be ticked.');
        $credits = $comparison->credits;
        self::assertNotNull($credits);
        self::assertSame($song->getPublicId()->toString(), $credits->songPublicId);
        self::assertSame(
            [['Post Malone, Luke Combs', $postMalone->getPublicId()->toString()], ['Luke Combs', null]],
            array_map(static fn (ProposedCredit $credit): array => [$credit->creditedName, $credit->artist->publicId], $credits->proposed),
        );
        self::assertEquals(
            [new MatchArtistAction(ArtistActionKind::Merge, $duetArtist->getPublicId()->toString(), 'Post Malone, Luke Combs', $credits->proposed[0]->artist, [$song->getPublicId()->toString()])],
            $comparison->artistActions,
        );
    }

    public function testAnArtistHoldingTheCandidatesIdTurnsTheMatchIntoATickedMerge(): void
    {
        $holder = $this->catalog->artist('Headhunterz', '6c1e0000-0000-4000-8000-000000000001');
        $artist = $this->catalog->artist('HeadHunterz');
        $this->musicBrainz->artists[$holder->getMbid() ?? ''] = self::headhunterz();

        $comparison = $this->compareArtist($artist, '6c1e0000-0000-4000-8000-000000000001');

        self::assertSame([], $comparison->fields);
        self::assertNull($comparison->credits);
        self::assertCount(1, $comparison->artistActions);
        $merge = $comparison->artistActions[0];
        self::assertSame(ArtistActionKind::Merge, $merge->kind);
        self::assertSame($artist->getPublicId()->toString(), $merge->artistPublicId);
        self::assertSame($holder->getPublicId()->toString(), $merge->target?->publicId);
        self::assertTrue($merge->ticked);
    }

    public function testAnArtistComparisonTicksTheIdAndEmptyFieldsButNotADifferentName(): void
    {
        $artist = $this->catalog->artist('HeadHunterz');
        $this->musicBrainz->artists['6c1e0000-0000-4000-8000-000000000001'] = self::headhunterz();

        $comparison = $this->compareArtist($artist, '6c1e0000-0000-4000-8000-000000000001');

        self::assertSame([], $comparison->artistActions);
        self::assertRow(MatchField::Mbid, null, '6c1e0000-0000-4000-8000-000000000001', ticked: true, row: $comparison->field(MatchField::Mbid));
        self::assertRow(MatchField::Name, 'HeadHunterz', 'Headhunterz', ticked: false, row: $comparison->field(MatchField::Name));
        self::assertRow(MatchField::Country, null, 'NL', ticked: true, row: $comparison->field(MatchField::Country));
        self::assertRow(MatchField::LifeSpanBegin, null, '1985-09-12', ticked: true, row: $comparison->field(MatchField::LifeSpanBegin));
        self::assertNull($comparison->field(MatchField::LifeSpanEnd));
    }

    public function testMusicBrainzUnavailableAnswers503(): void
    {
        $album = $this->addAlbum();
        $this->musicBrainz->releases[self::releaseId(1)] = new MusicBrainzUnavailable('HTTP 503');

        try {
            $this->handler()(new CompareMatchQuery(MatchItemType::Album, $album->getPublicId()->toString(), self::releaseId(1)));
            self::fail('An outage is reported.');
        } catch (ServiceUnavailableException $exception) {
            self::assertSame(['reason' => 'musicbrainz_unavailable'], $exception->details);
        }
    }

    public function testACandidateMusicBrainzDoesNotHaveAnswers404(): void
    {
        $album = $this->addAlbum();

        $this->expectException(NotFoundException::class);

        $this->handler()(new CompareMatchQuery(MatchItemType::Album, $album->getPublicId()->toString(), self::releaseId(9)));
    }

    public function testAnUnknownAlbumAnswers404WithoutAskingMusicBrainz(): void
    {
        try {
            $this->handler()(new CompareMatchQuery(MatchItemType::Album, new PublicId()->toString(), self::releaseId(1)));
            self::fail('An unknown album is not found.');
        } catch (NotFoundException) {
            self::assertSame([], $this->musicBrainz->calls);
        }
    }

    public function testAMalformedCandidateIdAnswers422WithoutAskingMusicBrainz(): void
    {
        $album = $this->addAlbum();

        try {
            $this->handler()(new CompareMatchQuery(MatchItemType::Album, $album->getPublicId()->toString(), 'not-an-mbid'));
            self::fail('A malformed ID is refused.');
        } catch (InvalidInputException) {
            self::assertSame([], $this->musicBrainz->calls);
        }
    }

    private function handler(): CompareMatchHandler
    {
        return new CompareMatchHandler(
            $this->musicBrainz,
            $this->scorer(),
            $this->snapshots(),
            new MatchComparisonBuilder($this->catalog, $this->artistPort()),
        );
    }

    private function compareAlbum(Album $album, MusicBrainzRelease $release): MatchComparison
    {
        $this->musicBrainz->releases[$release->id] = $release;

        return $this->handler()(new CompareMatchQuery(MatchItemType::Album, $album->getPublicId()->toString(), $release->id));
    }

    private function compareArtist(Artist $artist, string $candidateId): MatchComparison
    {
        return $this->handler()(new CompareMatchQuery(MatchItemType::Artist, $artist->getPublicId()->toString(), $candidateId));
    }

    private static function headhunterz(): MusicBrainzArtist
    {
        return new MusicBrainzArtist('6c1e0000-0000-4000-8000-000000000001', 'Headhunterz', 0, 'Headhunterz', 'Person', 'NL', 'Dutch hardstyle DJ', '1985-09-12', null, false);
    }

    /**
     * @param string|int|bool|list<string>|null $current
     * @param string|int|list<string>      $proposed
     */
    private static function assertRow(MatchField $field, string|int|bool|array|null $current, string|int|array $proposed, bool $ticked, ?MatchFieldRow $row): void
    {
        self::assertEquals(new MatchFieldRow($field, $current, $proposed, $ticked, locked: false), $row);
    }
}
