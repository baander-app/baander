<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Interface\Console;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Catalog\Application\Port\SongLyricSignature;
use App\Lyrics\Application\Command\ApplyLyricsCommand;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\CommandHandler\ApplyLyricsHandler;
use App\Lyrics\Application\CommandHandler\FetchLyricsHandler;
use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Lyrics\Application\DTO\LrclibUnavailable;
use App\Lyrics\Application\Exception\LyricsProviderUnavailableException;
use App\Lyrics\Application\Port\LrclibClientInterface;
use App\Lyrics\Application\Query\SearchLyricsQuery;
use App\Lyrics\Application\QueryHandler\SearchLyricsHandler;
use App\Lyrics\Application\Service\LyricsSongResolver;
use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Lyrics\Interface\Console\LyricsApplyCommand;
use App\Lyrics\Interface\Console\LyricsSearchCommand;
use App\Lyrics\Interface\Console\SongLyricsFetchCommand;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Tests\Unit\Lyrics\InMemoryQueuedLyricsFetches;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/** app:song:lyrics:fetch, app:lyrics:search and app:lyrics:apply run the lyrics use cases with full authority. */
final class SongLyricsCommandsTest extends TestCase
{
    private const string SONG = 'lyricsSongPublicId001';

    private LrclibClientInterface&Stub $lrclib;
    private LyricsRepositoryInterface $lyrics;
    private Uuid $songId;
    /** @var list<LibraryReadScope> the scopes the commands looked the song up in */
    private array $scopes = [];

    protected function setUp(): void
    {
        $this->songId = Uuid::v7();
        $this->lrclib = $this->createStub(LrclibClientInterface::class);
        $this->lyrics = new class implements LyricsRepositoryInterface {
            /** @var array<string, Lyrics> */
            public array $bySong = [];

            public function save(Lyrics $lyrics): void
            {
                $this->bySong[$lyrics->getSongId()->toString()] = $lyrics;
            }

            public function findBySongId(Uuid $songId): ?Lyrics
            {
                return $this->bySong[$songId->toString()] ?? null;
            }

            public function delete(Lyrics $lyrics): void
            {
                unset($this->bySong[$lyrics->getSongId()->toString()]);
            }
        };
    }

    public function testFetchStoresAndPrintsTheLyricsOfASongInAnyLibrary(): void
    {
        $this->lrclib->method('getBySignatureCached')->willReturn($this->providerLyrics());

        $tester = $this->fetch();
        self::assertSame(Command::SUCCESS, $tester->execute(['song' => self::SONG]), $tester->getDisplay());

        self::assertStringContainsString('This was a triumph', $tester->getDisplay());
        self::assertSame('This was a triumph', $this->lyrics->findBySongId($this->songId)?->getLyrics());
        self::assertTrue($this->scopes[0]->isUnrestricted(), 'CLI access is full authority.');
    }

    public function testFetchJsonIsTheLyricsResource(): void
    {
        $this->lrclib->method('getBySignatureCached')->willReturn($this->providerLyrics());

        $tester = $this->fetch();
        self::assertSame(Command::SUCCESS, $tester->execute(['song' => self::SONG, '--json' => true]));

        self::assertSame([
            'plainLyrics' => 'This was a triumph',
            'syncedLyrics' => '[00:01.00] This was a triumph',
            'source' => 'lrclib',
            'isInstrumental' => false,
        ], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testFetchWithNothingFoundSucceedsAndPrintsAnEmptyArrayAsJson(): void
    {
        $tester = $this->fetch();
        self::assertSame(Command::SUCCESS, $tester->execute(['song' => self::SONG, '--json' => true]));

        self::assertSame([], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertNull($this->lyrics->findBySongId($this->songId));
    }

    public function testFetchDuringAnOutageFails(): void
    {
        $this->lrclib->method('getBySignatureCached')->willReturn(new LrclibUnavailable('HTTP 503'));
        $this->lrclib->method('getBySignature')->willReturn(new LrclibUnavailable('HTTP 503'));

        $tester = $this->fetch();
        self::assertSame(Command::FAILURE, $tester->execute(['song' => self::SONG]));

        self::assertStringContainsString('LRCLIB is unavailable', $tester->getDisplay());
        self::assertNull($this->lyrics->findBySongId($this->songId));
    }

    public function testFetchRejectsAMalformedPublicIdAndFailsForAnUnknownSong(): void
    {
        $tester = $this->fetch();
        self::assertSame(Command::INVALID, $tester->execute(['song' => 'not-a-public-id']));
        self::assertStringContainsString('Invalid public ID format.', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['song' => (new PublicId())->toString()]));
        self::assertStringContainsString('Song not found.', $tester->getDisplay());
    }

    public function testSearchListsTheResultsWithTheirIds(): void
    {
        $this->lrclib->method('search')->willReturnCallback(fn(string $query): array => $query === 'Still Alive Portal'
            ? [new LrclibSearchResult(912345, 'Still Alive', 'GLaDOS', 'Portal', 175.4, false, 'This was a triumph', '[00:01.00] This was a triumph')]
            : []);

        $tester = new CommandTester(new LyricsSearchCommand($this->support()));
        self::assertSame(Command::SUCCESS, $tester->execute(['query' => ['Still', 'Alive', 'Portal']]));

        self::assertStringContainsString('912345', $tester->getDisplay());
        self::assertStringContainsString('2:55', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['query' => ['Still Alive Portal'], '--json' => true]));
        self::assertSame([[
            'id' => 912345,
            'trackName' => 'Still Alive',
            'artistName' => 'GLaDOS',
            'albumName' => 'Portal',
            'duration' => 175.4,
            'instrumental' => false,
            'plainLyrics' => 'This was a triumph',
            'syncedLyrics' => '[00:01.00] This was a triumph',
        ]], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testSearchWithoutAQueryIsInvalidAndAnOutageFails(): void
    {
        $this->lrclib->method('search')->willReturn(new LrclibUnavailable('timeout'));
        $tester = new CommandTester(new LyricsSearchCommand($this->support()));

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('Search query is required.', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['query' => ['Still Alive']]));
        self::assertStringContainsString(LyricsProviderUnavailableException::MESSAGE, $tester->getDisplay());
    }

    public function testApplyStoresTheResultForASongWithoutLyrics(): void
    {
        $this->lrclib->method('getById')->willReturn($this->providerLyrics());

        $tester = $this->apply();
        self::assertSame(Command::SUCCESS, $tester->execute(['result-id' => '912345', 'song' => self::SONG, '--json' => true]));

        self::assertSame('This was a triumph', json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['plainLyrics']);
        self::assertSame(912345, $this->lyrics->findBySongId($this->songId)?->getLrclibId());
        self::assertTrue($this->scopes[0]->isUnrestricted(), 'CLI access is full authority.');
    }

    public function testApplyToASongWithLyricsFailsAndKeepsThem(): void
    {
        $this->lyrics->save(Lyrics::create($this->songId, 'Existing lyrics', 'embedded'));
        $this->lrclib->method('getById')->willReturn($this->providerLyrics());

        $tester = $this->apply();
        self::assertSame(Command::FAILURE, $tester->execute(['result-id' => '912345', 'song' => self::SONG]));

        self::assertStringContainsString('The song already has lyrics.', $tester->getDisplay());
        self::assertSame('Existing lyrics', $this->lyrics->findBySongId($this->songId)?->getLyrics());
    }

    public function testApplyRejectsAResultIdThatIsNotAnInteger(): void
    {
        $tester = $this->apply();
        self::assertSame(Command::INVALID, $tester->execute(['result-id' => 'abc', 'song' => self::SONG]));

        self::assertStringContainsString('The result ID must be an integer.', $tester->getDisplay());
    }

    private function fetch(): CommandTester
    {
        return new CommandTester(new SongLyricsFetchCommand($this->support(), $this->resolver()));
    }

    private function apply(): CommandTester
    {
        return new CommandTester(new LyricsApplyCommand($this->support(), $this->resolver()));
    }

    private function providerLyrics(): LrclibResult
    {
        return new LrclibResult(912345, 'Still Alive', 'GLaDOS', 'Portal', 175.0, false, 'This was a triumph', '[00:01.00] This was a triumph');
    }

    private function support(): AdminCommandSupport
    {
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('findLyricSignature')->willReturn(new SongLyricSignature('Still Alive', 'GLaDOS', 'Portal', 175.0));

        return new AdminCommandSupport(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            FetchLyricsCommand::class => [new FetchLyricsHandler($songs, $this->lrclib, $this->lyrics, new NullLogger(), new InMemoryQueuedLyricsFetches())],
            SearchLyricsQuery::class => [new SearchLyricsHandler($this->lrclib)],
            ApplyLyricsCommand::class => [new ApplyLyricsHandler($this->lrclib, $this->lyrics, new NullLogger())],
        ]))]));
    }

    private function resolver(): LyricsSongResolver
    {
        $songs = $this->createStub(SongLookupInterface::class);
        $songs->method('findVisibleSongId')->willReturnCallback(function (PublicId $publicId, LibraryReadScope $scope): ?Uuid {
            $this->scopes[] = $scope;

            return $publicId->toString() === self::SONG ? $this->songId : null;
        });

        return new LyricsSongResolver($songs);
    }
}
