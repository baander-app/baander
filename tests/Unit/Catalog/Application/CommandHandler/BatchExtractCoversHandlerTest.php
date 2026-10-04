<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Catalog\Application\CommandHandler\BatchExtractCoversHandler;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class BatchExtractCoversHandlerTest extends TestCase
{
    #[DataProvider('failureLogging')]
    public function testDispatchFailureStopsFanoutAndPreservesOriginalException(bool $loggerFails): void
    {
        $ids = [Uuid::v7(), Uuid::v7(), Uuid::v7()];
        $repository = $this->createMock(AlbumRepositoryInterface::class);
        $repository->expects($this->never())->method('findCoverlessAlbumIds');
        $repository->expects($this->once())->method('findCoverlessAlbumIdsAfter')->with(null, 500)->willReturn($ids);
        $failure = new RuntimeException('Cover queue unavailable');
        $bus = $this->createMock(MessageBusInterface::class);
        $attempted = [];
        $bus->expects($this->exactly(2))->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$attempted, $ids, $failure): Envelope {
                self::assertInstanceOf(ExtractAlbumCoverCommand::class, $message);
                $attempted[] = $message->getAlbumId();
                if ($message->getAlbumId()->equals($ids[1])) {
                    throw $failure;
                }
                return new Envelope($message);
            },
        );
        $logger = $this->createMock(LoggerInterface::class);
        $warning = $logger->expects($this->once())->method('warning')->with(
            'Failed to dispatch cover extraction for album {id}',
            ['id' => $ids[1]->toString(), 'error' => $failure->getMessage(), 'dispatched' => 1],
        );
        if ($loggerFails) {
            $warning->willThrowException(new RuntimeException('Logger unavailable'));
        }
        $logger->expects($this->never())->method('info');

        try {
            (new BatchExtractCoversHandler($repository, $bus, $logger))(new BatchExtractCoversCommand());
            self::fail('Failed fanout must not return a successful dispatch count.');
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertSame([$ids[0], $ids[1]], $attempted);
    }

    /** @return iterable<string, array{bool}> */
    public static function failureLogging(): iterable
    {
        yield 'warning recorded' => [false];
        yield 'warning logger unavailable' => [true];
    }

    public function testSuccessfulFanoutReturnsAcceptedCountAcrossCursorPages(): void
    {
        $ids = array_map(static fn (): Uuid => Uuid::v7(), range(1, 501));
        $repository = $this->createMock(AlbumRepositoryInterface::class);
        $cursors = [];
        $repository->expects($this->never())->method('findCoverlessAlbumIds');
        $repository->expects($this->exactly(3))->method('findCoverlessAlbumIdsAfter')->willReturnCallback(
            static function (?Uuid $after, int $limit) use ($ids, &$cursors): array {
                self::assertSame(500, $limit);
                $cursors[] = $after;
                $start = $after === null ? 0 : array_search($after, $ids, true) + 1;
                return array_slice($ids, $start, $limit);
            },
        );
        $bus = $this->createMock(MessageBusInterface::class);
        $accepted = [];
        $bus->expects($this->exactly(501))->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$accepted): Envelope {
                self::assertInstanceOf(ExtractAlbumCoverCommand::class, $message);
                $accepted[] = $message->getAlbumId();
                return new Envelope($message);
            },
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $logger->expects($this->once())->method('info')->with('Batch cover extraction completed', ['dispatched' => 501]);

        $count = (new BatchExtractCoversHandler($repository, $bus, $logger))(new BatchExtractCoversCommand());

        self::assertSame(501, $count);
        self::assertSame($ids, $accepted);
        self::assertSame([null, $ids[499], $ids[500]], $cursors);
    }
    #[DataProvider('coverlessSetChanges')]
    public function testEveryOriginalAlbumIsDispatchedOnceWhileTheCoverlessSetChanges(bool $shrinks): void
    {
        $ids = array_map(static fn (): Uuid => Uuid::v7(), range(1, 501));
        usort($ids, static fn (Uuid $left, Uuid $right): int => strcmp($left->toString(), $right->toString()));
        $remaining = array_combine(array_map(static fn (Uuid $id): string => $id->toString(), $ids), $ids);
        $repository = $this->createMock(AlbumRepositoryInterface::class);
        $repository->expects($this->never())->method('findCoverlessAlbumIds');
        $cursors = [];
        $repository->expects($this->exactly(3))->method('findCoverlessAlbumIdsAfter')->willReturnCallback(
            static function (?Uuid $after, int $limit) use (&$remaining, &$cursors): array {
                self::assertSame(500, $limit);
                $cursors[] = $after;
                $page = array_values(array_filter($remaining, static fn (Uuid $id): bool => $after === null || strcmp($id->toString(), $after->toString()) > 0));
                return array_slice($page, 0, $limit);
            },
        );
        $accepted = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(501))->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$accepted, &$remaining, $shrinks): Envelope {
                self::assertInstanceOf(ExtractAlbumCoverCommand::class, $message);
                $id = $message->getAlbumId()->toString();
                $accepted[] = $id;
                if ($shrinks) {
                    unset($remaining[$id]);
                }
                return new Envelope($message);
            },
        );
        $count = (new BatchExtractCoversHandler($repository, $bus, new \Psr\Log\NullLogger()))(new BatchExtractCoversCommand());
        self::assertSame(501, $count);
        self::assertSame(array_map(static fn (Uuid $id): string => $id->toString(), $ids), $accepted);
        self::assertSame([null, $ids[499], $ids[500]], $cursors);
    }

    /** @return iterable<string, array{bool}> */
    public static function coverlessSetChanges(): iterable
    {
        yield 'accepted jobs immediately acquire covers' => [true];
        yield 'albums permanently remain coverless' => [false];
    }

}
