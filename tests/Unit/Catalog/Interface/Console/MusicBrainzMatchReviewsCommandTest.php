<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Console;

use App\Catalog\Application\Query\MusicBrainzMatch\ListMatchReviewsQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchReview;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchReviewPage;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchReviewReadInterface;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\ListMatchReviewsHandler;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Interface\Console\MusicBrainzMatchReviewsCommand;
use App\Catalog\Interface\Controller\MusicBrainzMatchController;
use App\Shared\Interface\Console\AdminCommandSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/** app:metadata:match:reviews lists what GET /api/admin/metadata/match/reviews lists and prints the same data. */
final class MusicBrainzMatchReviewsCommandTest extends TestCase
{
    /** @var list<array{?string, int}> the cursor and limit of each read */
    private array $reads = [];

    public function testJsonIsTheDataTheEndpointReturns(): void
    {
        $bus = $this->bus(self::page());

        $response = new MusicBrainzMatchController($bus)->reviews(Request::create('/api/admin/metadata/match/reviews', 'GET', ['limit' => '2', 'cursor' => 'the-cursor']));
        $endpoint = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];

        $tester = new CommandTester(new MusicBrainzMatchReviewsCommand(new AdminCommandSupport($bus)));
        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '2', '--cursor' => 'the-cursor', '--json' => true]));

        self::assertSame($endpoint, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame([['the-cursor', 2], ['the-cursor', 2]], $this->reads);
        self::assertSame([
            'items' => [
                [
                    'albumPublicId' => 'newestReviewedAlbumId',
                    'title' => 'F-1 Trillion: Long Bed',
                    'reason' => 'track_count_differs',
                    'checkedAt' => '2026-11-14T12:36:07+00:00',
                    'bestScore' => 84,
                ],
                [
                    'albumPublicId' => 'olderReviewedAlbumIds',
                    'title' => 'Unknown Demos',
                    'reason' => 'no_candidates',
                    'checkedAt' => '2026-11-14T09:00:00+00:00',
                    'bestScore' => null,
                ],
            ],
            'next_cursor' => 'the-next-cursor',
            'has_next_page' => true,
            'per_page' => 2,
        ], $endpoint);
    }

    public function testTheTableListsEachAlbumAndHowToContinue(): void
    {
        $tester = new CommandTester(new MusicBrainzMatchReviewsCommand(new AdminCommandSupport($this->bus(self::page()))));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertSame([[null, ListMatchReviewsQuery::DEFAULT_LIMIT]], $this->reads);
        self::assertMatchesRegularExpression('/newestReviewedAlbumId\s+F-1 Trillion: Long Bed\s+track_count_differs\s+2026-11-14T12:36:07\+00:00\s+84/', $display);
        self::assertMatchesRegularExpression('/olderReviewedAlbumIds\s+Unknown Demos\s+no_candidates\s+2026-11-14T09:00:00\+00:00\s+-/', $display);
        self::assertStringContainsString('More albums follow. Continue with --cursor=the-next-cursor', $display);
    }

    public function testAnEmptyListSaysSo(): void
    {
        $tester = new CommandTester(new MusicBrainzMatchReviewsCommand(new AdminCommandSupport($this->bus(new MatchReviewPage([], null, false, ListMatchReviewsQuery::DEFAULT_LIMIT)))));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringContainsString('No album needs a review.', $tester->getDisplay());
        self::assertStringNotContainsString('--cursor', $tester->getDisplay());
    }

    public function testALimitThatIsNotAnIntegerOrOutOfRangeIsRejected(): void
    {
        $command = new MusicBrainzMatchReviewsCommand(new AdminCommandSupport($this->bus(self::page())));

        self::assertSame(Command::INVALID, new CommandTester($command)->execute(['--limit' => 'ten']));
        self::assertSame(Command::INVALID, new CommandTester($command)->execute(['--limit' => (string) (ListMatchReviewsQuery::MAX_LIMIT + 1)]));
        self::assertSame([], $this->reads);
    }

    private static function page(): MatchReviewPage
    {
        return new MatchReviewPage(
            items: [
                new MatchReview('newestReviewedAlbumId', 'F-1 Trillion: Long Bed', AlbumMatchReviewReason::TrackCountDiffers, new \DateTimeImmutable('2026-11-14 12:36:07.500000 UTC'), 84),
                new MatchReview('olderReviewedAlbumIds', 'Unknown Demos', AlbumMatchReviewReason::NoCandidates, new \DateTimeImmutable('2026-11-14 09:00:00 UTC'), null),
            ],
            nextCursor: 'the-next-cursor',
            hasNextPage: true,
            perPage: 2,
        );
    }

    private function bus(MatchReviewPage $page): MessageBus
    {
        $reviews = $this->createStub(MatchReviewReadInterface::class);
        $reviews->method('page')->willReturnCallback(function (?string $cursor, int $limit) use ($page): MatchReviewPage {
            $this->reads[] = [$cursor, $limit];

            return $page;
        });

        return new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            ListMatchReviewsQuery::class => [new ListMatchReviewsHandler($reviews)],
        ]))]);
    }
}
