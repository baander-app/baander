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
        $repository->expects($this->once())->method('findCoverlessAlbumIds')->with(500, 0)->willReturn($ids);
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

    public static function failureLogging(): iterable
    {
        yield 'warning recorded' => [false];
        yield 'warning logger unavailable' => [true];
    }

    public function testSuccessfulFanoutReturnsAcceptedCountAcrossCurrentOffsetPages(): void
    {
        // This pins existing pagination behavior; it does not certify shrinking-set safety.
        $ids = array_map(static fn (): Uuid => Uuid::v7(), range(1, 501));
        $repository = $this->createMock(AlbumRepositoryInterface::class);
        $offsets = [];
        $repository->expects($this->exactly(3))->method('findCoverlessAlbumIds')->willReturnCallback(
            static function (int $limit, int $offset) use ($ids, &$offsets): array {
                self::assertSame(500, $limit);
                $offsets[] = $offset;
                return array_slice($ids, $offset, $limit);
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
        self::assertSame([0, 500, 1000], $offsets);
    }
}
