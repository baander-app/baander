<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class CoverBatchRoutingTest extends KernelTestCase
{
    public function testBatchRunsOnlyAfterTheAsyncWorkerReceivesIt(): void
    {
        self::bootKernel();

        $albumId = Uuid::v7();
        $repository = $this->createMock(AlbumRepositoryInterface::class);
        $repository
            ->expects(self::exactly(2))
            ->method('findCoverlessAlbumIdsAfter')
            ->willReturnOnConsecutiveCalls([$albumId], []);

        self::getContainer()->set(AlbumRepositoryInterface::class, $repository);

        $bus = self::getContainer()->get(MessageBusInterface::class);
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $bus->dispatch(new BatchExtractCoversCommand());

        $queued = $transport->getSent();
        self::assertCount(1, $queued);
        self::assertInstanceOf(BatchExtractCoversCommand::class, $queued[0]->getMessage());

        $handled = $bus->dispatch($queued[0]->with(new ReceivedStamp('async')));

        self::assertSame(1, $handled->last(HandledStamp::class)?->getResult());
        $queued = $transport->getSent();
        self::assertCount(2, $queued);
        self::assertInstanceOf(ExtractAlbumCoverCommand::class, $queued[1]->getMessage());
        self::assertTrue($albumId->equals($queued[1]->getMessage()->getAlbumId()));
    }
}
