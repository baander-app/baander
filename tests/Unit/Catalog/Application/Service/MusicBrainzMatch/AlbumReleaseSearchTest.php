<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Service\MusicBrainzMatch;

use App\Catalog\Application\Service\MusicBrainzMatch\AlbumReleaseSearch;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchQueryFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * The release search of an album: its title first and, when that finds no release and the title
 * ends in a comma-separated list in square brackets, the title without the list. MusicBrainz is a
 * fake that answers each search by its title and artist.
 */
final class AlbumReleaseSearchTest extends TestCase
{
    use MatchQueryFixtures;

    private const string STORED_TITLE = 'Ten Thousand Fists [Reprise Records,9362-49433-2,EU]';
    private const string SHORTENED_TITLE = 'Ten Thousand Fists';
    private const string ARTIST = 'Disturbed';
    private const string FIRST_SEARCH = self::STORED_TITLE . '|' . self::ARTIST;
    private const string SECOND_SEARCH = self::SHORTENED_TITLE . '|' . self::ARTIST;

    protected function setUp(): void
    {
        $this->setUpLibrary();
    }

    public function testATitleThatFindsNoReleaseIsSearchedAgainWithoutTheListAtItsEnd(): void
    {
        $hit = self::summary(self::release(self::releaseId(1), title: self::SHORTENED_TITLE));
        $this->musicBrainz->releaseSearches[self::SECOND_SEARCH] = [$hit];

        $found = new AlbumReleaseSearch($this->musicBrainz)->find(self::STORED_TITLE, self::ARTIST);

        self::assertSame(self::SHORTENED_TITLE, $found->title);
        self::assertSame([$hit], $found->hits);
        self::assertSame(['findReleases:' . self::FIRST_SEARCH, 'findReleases:' . self::SECOND_SEARCH], $this->musicBrainz->calls);
    }

    public function testATitleThatFindsReleasesIsSearchedOnce(): void
    {
        $hit = self::summary(self::release(self::releaseId(1), title: self::STORED_TITLE));
        $this->musicBrainz->releaseSearches[self::FIRST_SEARCH] = [$hit];
        $this->musicBrainz->releaseSearches[self::SECOND_SEARCH] = [self::summary(self::release(self::releaseId(2), title: self::SHORTENED_TITLE))];

        $found = new AlbumReleaseSearch($this->musicBrainz)->find(self::STORED_TITLE, self::ARTIST);

        self::assertSame(self::STORED_TITLE, $found->title);
        self::assertSame([$hit], $found->hits);
        self::assertSame(['findReleases:' . self::FIRST_SEARCH], $this->musicBrainz->calls);
    }

    #[DataProviderExternal(ReleaseSearchTitleTest::class, 'titlesThatStay')]
    public function testATitleWithoutSuchAListIsSearchedOnceThoughItFindsNothing(string $title): void
    {
        $found = new AlbumReleaseSearch($this->musicBrainz)->find($title, self::ARTIST);

        self::assertSame($title, $found->title);
        self::assertSame([], $found->hits);
        self::assertSame(['findReleases:' . $title . '|' . self::ARTIST], $this->musicBrainz->calls);
    }

    public function testASecondSearchThatFindsNothingStillReportsTheShortenedTitle(): void
    {
        $found = new AlbumReleaseSearch($this->musicBrainz)->find(self::STORED_TITLE, self::ARTIST);

        self::assertSame(self::SHORTENED_TITLE, $found->title);
        self::assertSame([], $found->hits);
        self::assertSame(['findReleases:' . self::FIRST_SEARCH, 'findReleases:' . self::SECOND_SEARCH], $this->musicBrainz->calls);
    }

    public function testBothSearchesWaitForTheirTurnAsLongAsTheCallerSays(): void
    {
        $musicBrainz = $this->createMock(MusicBrainzLookupInterface::class);
        $musicBrainz->expects(self::exactly(2))
            ->method('findReleases')
            ->with(self::anything(), self::ARTIST, MusicBrainzSlotWait::Unbounded)
            ->willReturn([]);

        new AlbumReleaseSearch($musicBrainz)->find(self::STORED_TITLE, self::ARTIST, MusicBrainzSlotWait::Unbounded);
    }

    /** @return iterable<string, array{MusicBrainzRejected|MusicBrainzUnavailable, class-string<\Throwable>, string}> */
    public static function secondSearchFailures(): iterable
    {
        yield 'an outage' => [new MusicBrainzUnavailable('HTTP 503'), ServiceUnavailableException::class, 'musicbrainz_unavailable'];
        yield 'a refusal' => [new MusicBrainzRejected(400), ConflictException::class, 'musicbrainz_rejected'];
    }

    /** @param class-string<\Throwable> $exception */
    #[DataProvider('secondSearchFailures')]
    public function testTheSecondSearchFailsAsTheFirstDoes(MusicBrainzRejected|MusicBrainzUnavailable $answer, string $exception, string $reason): void
    {
        $this->musicBrainz->releaseSearches[self::SECOND_SEARCH] = $answer;

        try {
            new AlbumReleaseSearch($this->musicBrainz)->find(self::STORED_TITLE, self::ARTIST);
            self::fail('The second search was expected to fail.');
        } catch (ServiceUnavailableException|ConflictException $failure) {
            self::assertInstanceOf($exception, $failure);
            self::assertSame(['reason' => $reason], $failure->details);
        }
    }
}
