<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Application\QueryHandler;

use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Lyrics\Application\DTO\LrclibUnavailable;
use App\Lyrics\Application\Exception\LyricsProviderUnavailableException;
use App\Lyrics\Application\Port\LrclibClientInterface;
use App\Lyrics\Application\Query\SearchLyricsQuery;
use App\Lyrics\Application\QueryHandler\SearchLyricsHandler;
use App\Shared\Application\Exception\InvalidInputException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class SearchLyricsHandlerTest extends TestCase
{
    public function testReturnsTheMatchesForTheTrimmedQuery(): void
    {
        $match = new LrclibSearchResult(1, 'Still Alive', 'Portal', 'OST', 175.0, false, 'This was a triumph', null);
        $lrclib = $this->createMock(LrclibClientInterface::class);
        $lrclib->expects($this->once())->method('search')->with('Still Alive')->willReturn([$match]);

        $this->assertSame([$match], (new SearchLyricsHandler($lrclib))(new SearchLyricsQuery('  Still Alive ')));
    }

    public function testNoMatchIsAnEmptyList(): void
    {
        $lrclib = $this->createStub(LrclibClientInterface::class);
        $lrclib->method('search')->willReturn([]);

        $this->assertSame([], (new SearchLyricsHandler($lrclib))(new SearchLyricsQuery('nothing matches this')));
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    public function testABlankQueryIsInvalidInput(string $query): void
    {
        $lrclib = $this->createMock(LrclibClientInterface::class);
        $lrclib->expects($this->never())->method('search');

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Search query is required.');

        (new SearchLyricsHandler($lrclib))(new SearchLyricsQuery($query));
    }

    public function testAnOutageIsReportedAsProviderUnavailable(): void
    {
        $lrclib = $this->createStub(LrclibClientInterface::class);
        $lrclib->method('search')->willReturn(new LrclibUnavailable('HTTP 500'));

        $this->expectException(LyricsProviderUnavailableException::class);

        (new SearchLyricsHandler($lrclib))(new SearchLyricsQuery('Still Alive'));
    }
}
