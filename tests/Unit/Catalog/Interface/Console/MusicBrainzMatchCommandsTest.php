<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Console;

use App\Catalog\Application\Query\MusicBrainzMatch\CompareMatchQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\SearchMatchCandidatesQuery;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\CompareMatchHandler;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchComparisonBuilder;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\SearchMatchCandidatesHandler;
use App\Catalog\Application\Service\MusicBrainzMatch\AlbumReleaseSearch;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Catalog\Interface\Console\MusicBrainzMatchCompareCommand;
use App\Catalog\Interface\Console\MusicBrainzMatchSearchCommand;
use App\Catalog\Interface\Controller\MusicBrainzMatchController;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchQueryFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/**
 * app:metadata:match:search and app:metadata:match:compare dispatch the queries the candidates and
 * comparison routes dispatch, print the same data with --json, and print in their tables the IDs
 * and row keys an apply takes.
 */
final class MusicBrainzMatchCommandsTest extends TestCase
{
    use MatchQueryFixtures;

    private const string HEADHUNTERZ_ID = '6c1e0000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        $this->setUpLibrary();
    }

    public function testSearchJsonIsTheDataTheCandidatesRouteReturnsForARefinedSearch(): void
    {
        [$album] = $this->longBed();
        $release = self::release(self::releaseId(1), [3], title: 'F-1 Trillion');
        $this->musicBrainz->releaseSearches['F-1 Trillion|Post Malone'] = [self::summary($release)];
        $this->musicBrainz->releases[$release->id] = $release;
        $publicId = $album->getPublicId()->toString();

        $endpoint = $this->controller()->candidates(
            'album',
            $publicId,
            Request::create('/api/admin/metadata/match/album/' . $publicId . '/candidates', 'GET', ['title' => 'F-1 Trillion', 'artist' => 'Post Malone']),
        ) |> self::data(...);
        $search = $this->search();

        self::assertSame(Command::SUCCESS, $search->execute(['type' => 'album', 'public-id' => $publicId, '--title' => 'F-1 Trillion', '--artist' => 'Post Malone', '--json' => true]));

        self::assertSame($endpoint, $search->getDisplay() |> self::decoded(...));
        self::assertSame('F-1 Trillion', $endpoint['title']);
        self::assertSame([$release->id], array_column($endpoint['candidates'], 'id'));
        self::assertSame(['findReleases:F-1 Trillion|Post Malone', 'fetchRelease:' . $release->id, 'findReleases:F-1 Trillion|Post Malone', 'fetchRelease:' . $release->id], $this->musicBrainz->calls);
    }

    /** An empty artist parameter and --no-artist both search by the title alone. */
    public function testATitleOnlySearchIsAnEmptyArtistOnTheRouteAndNoArtistOnTheCommand(): void
    {
        [$album, $release] = $this->longBed();
        $this->musicBrainz->releaseSearches[self::ALBUM_TITLE . '|'] = [self::summary($release)];
        $publicId = $album->getPublicId()->toString();

        $endpoint = $this->controller()->candidates(
            'album',
            $publicId,
            Request::create('/api/admin/metadata/match/album/' . $publicId . '/candidates', 'GET', ['artist' => '']),
        ) |> self::data(...);
        $search = $this->search();

        self::assertSame(Command::SUCCESS, $search->execute(['type' => 'album', 'public-id' => $publicId, '--no-artist' => true, '--json' => true]));

        self::assertSame($endpoint, $search->getDisplay() |> self::decoded(...));
        self::assertNull($endpoint['artist']);
        self::assertSame([$release->id], array_column($endpoint['candidates'], 'id'));
        self::assertSame(['findReleases:' . self::ALBUM_TITLE . '|', 'fetchRelease:' . $release->id], array_slice($this->musicBrainz->calls, 0, 2));

        $both = $this->search();
        self::assertSame(Command::INVALID, $both->execute(['type' => 'album', 'public-id' => $publicId, '--artist' => 'Post Malone', '--no-artist' => true]));
        self::assertStringContainsString('Give --artist or --no-artist, not both.', $both->getDisplay());
    }

    public function testSearchListsEachCandidateWithTheIdCompareTakes(): void
    {
        [$album] = $this->longBed();
        $release = self::release(self::releaseId(1), [3]);
        $this->musicBrainz->releaseSearches[self::ALBUM_TITLE . '|' . self::ALBUM_ARTIST] = [self::summary($release)];
        $this->musicBrainz->releases[$release->id] = $release;
        $publicId = $album->getPublicId()->toString();
        $search = $this->search();

        self::assertSame(Command::SUCCESS, $search->execute(['type' => 'album', 'public-id' => $publicId]));

        $display = $search->getDisplay();
        self::assertStringContainsString('MusicBrainz releases for "F-1 Trillion: Long Bed" by "Post Malone"', $display);
        self::assertMatchesRegularExpression('/' . $release->id . '\s+100\s+F-1 Trillion: Long Bed\s+Post Malone\s+2024-08-16\s+XW\s+-\s+Digital Media\s+3\s+no/', $display);
        self::assertStringContainsString(sprintf('app:metadata:match:compare album %s <ID>', $publicId), $display);
    }

    public function testAnArtistSearchListsArtistsAndSaysWhenThereAreNone(): void
    {
        $artist = $this->catalog->artist('HeadHunterz');
        $this->musicBrainz->artistSearch = [new MusicBrainzArtist(self::HEADHUNTERZ_ID, 'Headhunterz', 0, 'Headhunterz', 'Person', 'NL', 'Dutch hardstyle DJ', '1985-09-12', null, false)];
        $search = $this->search();

        self::assertSame(Command::SUCCESS, $search->execute(['type' => 'artist', 'public-id' => $artist->getPublicId()->toString()]));
        self::assertMatchesRegularExpression('/' . self::HEADHUNTERZ_ID . '\s+\d+\s+Headhunterz\s+Dutch hardstyle DJ\s+Person\s+NL\s+1985-09-12 to now/', $search->getDisplay());

        $this->musicBrainz->artistSearch = [];
        $empty = $this->search();
        self::assertSame(Command::SUCCESS, $empty->execute(['type' => 'artist', 'public-id' => $artist->getPublicId()->toString()]));
        self::assertStringContainsString('MusicBrainz has no artist that fits.', $empty->getDisplay());
    }

    public function testCompareJsonIsTheDataTheComparisonRouteReturns(): void
    {
        [$album, $release] = $this->longBed();
        $publicId = $album->getPublicId()->toString();

        $endpoint = $this->controller()->comparison(
            'album',
            $publicId,
            Request::create('/api/admin/metadata/match/album/' . $publicId . '/comparison', 'GET', ['candidate' => $release->id]),
        ) |> self::data(...);
        $compare = $this->compare();

        self::assertSame(Command::SUCCESS, $compare->execute(['type' => 'album', 'public-id' => $publicId, 'candidate' => $release->id, '--json' => true]));

        self::assertSame($endpoint, $compare->getDisplay() |> self::decoded(...));
        self::assertSame($release->id, $endpoint['candidateId']);
        self::assertCount(3, $endpoint['tracks']);
        self::assertCount(1, $endpoint['artistActions'], 'The combined "Post Malone, Luke Combs" artist merges into Post Malone.');
    }

    public function testALockedFieldArrivesLockedAndUntickedWithWhetherItWouldChange(): void
    {
        [$album, $release] = $this->longBed();
        $album->lockField('year');
        $publicId = $album->getPublicId()->toString();

        $endpoint = $this->controller()->comparison(
            'album',
            $publicId,
            Request::create('/api/admin/metadata/match/album/' . $publicId . '/comparison', 'GET', ['candidate' => $release->id]),
        ) |> self::data(...);

        $fields = array_column($endpoint['fields'], null, 'key');
        self::assertSame(['ticked' => false, 'locked' => true, 'changes' => true], array_intersect_key($fields['year'], ['ticked' => 0, 'locked' => 0, 'changes' => 0]));
        self::assertSame(['ticked' => false, 'locked' => false, 'changes' => false], array_intersect_key($fields['title'], ['ticked' => 0, 'locked' => 0, 'changes' => 0]));
        self::assertSame(['ticked' => true, 'changes' => true], array_intersect_key($endpoint['credits'], ['ticked' => 0, 'changes' => 0]));

        $compare = $this->compare();
        self::assertSame(Command::SUCCESS, $compare->execute(['type' => 'album', 'public-id' => $publicId, 'candidate' => $release->id]));
        self::assertMatchesRegularExpression('/\byear\s+2023\s+2024\s+locked/', $compare->getDisplay());
    }

    public function testCompareListsTheRowKeysSongsAndArtistsAnApplyTakes(): void
    {
        [$album, $release, $songs, $duetArtist] = $this->longBed();
        $compare = $this->compare();

        self::assertSame(Command::SUCCESS, $compare->execute(['type' => 'album', 'public-id' => $album->getPublicId()->toString(), 'candidate' => $release->id]));

        $display = $compare->getDisplay();
        $duet = $songs[1]->getPublicId()->toString();
        self::assertStringContainsString(sprintf('Album %s compared with MusicBrainz release %s.', $album->getPublicId()->toString(), $release->id), $display);
        self::assertMatchesRegularExpression('/releaseMbid\s+-\s+' . $release->id . '\s+yes/', $display);
        self::assertMatchesRegularExpression('/year\s+2023\s+2024\s+no/', $display);
        self::assertMatchesRegularExpression('/' . $duet . ':mbid\s+-\s+' . self::recordingId(1, 2) . '\s+yes/', $display);
        self::assertMatchesRegularExpression(
            '/' . $duet . ':credits\s+Post Malone, Luke Combs \(primary\)\s+Post Malone as "Post Malone, Luke Combs" \(primary, takes the ID\); Luke Combs \(featured, new artist\)\s+yes/',
            $display,
        );
        self::assertMatchesRegularExpression('/1\s+2\s+Track 1-2\s+2:37\s+' . $duet . '\s+Track 1-2\s+2:39/', $display);
        self::assertMatchesRegularExpression(
            '/' . $duetArtist->getPublicId()->toString() . '\s+Post Malone, Luke Combs\s+merge\s+Post Malone \(' . PublicId::PATTERN . '\)\s+yes\s+' . $duet . ':credits/',
            $display,
        );
    }

    /** The routes take any type segment and answer it as invalid input, as the commands do. */
    public function testATypeThatIsNotAlbumSongOrArtistIsInvalidInputOnBothPaths(): void
    {
        $search = $this->search();
        $compare = $this->compare();

        self::assertSame(Command::INVALID, $search->execute(['type' => 'release', 'public-id' => 'longBedAlbumPublicId1']));
        self::assertSame(Command::INVALID, $compare->execute(['type' => 'albums', 'public-id' => 'longBedAlbumPublicId1', 'candidate' => self::releaseId(1)]));
        self::assertStringContainsString('The type must be album, song or artist.', $search->getDisplay());

        foreach ([
            fn (): mixed => $this->controller()->candidates('release', 'longBedAlbumPublicId1', Request::create('/')),
            fn (): mixed => $this->controller()->comparison('albums', 'longBedAlbumPublicId1', Request::create('/', 'GET', ['candidate' => self::releaseId(1)])),
        ] as $route) {
            try {
                $route();
                self::fail('The route accepted a type that is not album, song or artist.');
            } catch (InvalidInputException $invalid) {
                self::assertSame('The type must be album, song or artist.', $invalid->getMessage());
            }
        }
        self::assertSame([], $this->musicBrainz->calls);
    }

    public function testAMusicBrainzOutageFailsWithTheApisMessage(): void
    {
        [$album] = $this->longBed();
        $this->musicBrainz->releaseSearches[self::ALBUM_TITLE . '|' . self::ALBUM_ARTIST] = new MusicBrainzUnavailable('HTTP 503');
        $search = $this->search();

        self::assertSame(Command::FAILURE, $search->execute(['type' => 'album', 'public-id' => $album->getPublicId()->toString(), '--json' => true]));

        self::assertStringContainsString('MusicBrainz is unavailable. Try again later.', $search->getDisplay());
    }

    public function testACandidateMusicBrainzDoesNotHaveFailsAndAMalformedOneIsInvalid(): void
    {
        [$album] = $this->longBed();
        $publicId = $album->getPublicId()->toString();
        $compare = $this->compare();

        self::assertSame(Command::FAILURE, $compare->execute(['type' => 'album', 'public-id' => $publicId, 'candidate' => self::releaseId(9)]));
        self::assertStringContainsString('MusicBrainz has no such release.', $compare->getDisplay());

        self::assertSame(Command::INVALID, $compare->execute(['type' => 'album', 'public-id' => $publicId, 'candidate' => 'not-an-id']));
    }

    /**
     * An album of three tracks credited to Post Malone, whose second track is a duet tagged
     * "Post Malone, Luke Combs" and credited to an artist of that name, with the release it fits.
     *
     * @return array{Album, MusicBrainzRelease, list<Song>, Artist}
     */
    private function longBed(): array
    {
        $album = $this->addAlbum(year: 2023);
        $release = self::release(self::releaseId(1), [3], ['1-2' => ['artistCredit' => self::credit(self::ALBUM_ARTIST, 'Luke Combs')]]);
        $this->musicBrainz->releases[$release->id] = $release;
        $songs = $this->addSongsOf($album, $release);
        $postMalone = $this->catalog->artist(self::ALBUM_ARTIST);
        $duetArtist = $this->catalog->artist('Post Malone, Luke Combs');
        $this->catalog->creditAlbum($album, $postMalone, ArtistRole::Primary);
        foreach ($songs as $song) {
            $this->catalog->creditSong($song, $song->getTrack() === 2 ? $duetArtist : $postMalone, ArtistRole::Primary);
        }

        return [$album, $release, $songs, $duetArtist];
    }

    private function controller(): MusicBrainzMatchController
    {
        return new MusicBrainzMatchController($this->bus());
    }

    private function search(): CommandTester
    {
        return new CommandTester(new MusicBrainzMatchSearchCommand(new AdminCommandSupport($this->bus())));
    }

    private function compare(): CommandTester
    {
        return new CommandTester(new MusicBrainzMatchCompareCommand(new AdminCommandSupport($this->bus())));
    }

    private function bus(): MessageBus
    {
        return new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            SearchMatchCandidatesQuery::class => [new SearchMatchCandidatesHandler($this->musicBrainz, $this->scorer(), $this->snapshots(), new AlbumReleaseSearch($this->musicBrainz))],
            CompareMatchQuery::class => [new CompareMatchHandler(
                $this->musicBrainz,
                $this->scorer(),
                $this->snapshots(),
                new MatchComparisonBuilder($this->catalog, $this->artistPort()),
            )],
        ]))]);
    }

    /** @return array<string, mixed> */
    private static function data(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }

    /** @return array<string, mixed> */
    private static function decoded(string $display): array
    {
        return json_decode($display, true, flags: JSON_THROW_ON_ERROR);
    }
}
