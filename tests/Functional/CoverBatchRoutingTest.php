<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Domain\Model\JobStatus;
use App\Shared\Domain\Model\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
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

    public function testConsoleRunsTheBatchInlineAndQueuesTheSameExtractionJobs(): void
    {
        self::bootKernel();

        $albumIds = [Uuid::v7(), Uuid::v7()];
        $repository = $this->createMock(AlbumRepositoryInterface::class);
        $repository->method('countCoverlessAlbums')->willReturn(2);
        $repository
            ->expects(self::exactly(2))
            ->method('findCoverlessAlbumIdsAfter')
            ->willReturnOnConsecutiveCalls($albumIds, []);

        self::getContainer()->set(AlbumRepositoryInterface::class, $repository);

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:album:extract-covers'));

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        // The batch itself ran here; only its fan-out reached the queue, as when the worker handles it.
        $queued = array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent());
        self::assertCount(2, $queued);
        self::assertContainsOnlyInstancesOf(ExtractAlbumCoverCommand::class, $queued);
        self::assertSame(
            array_map(static fn (Uuid $id): string => $id->toString(), $albumIds),
            array_map(static fn (ExtractAlbumCoverCommand $message): string => $message->getAlbumId()->toString(), $queued),
        );

        self::assertSame(1, preg_match('/Batch job ID: (\S+)/', preg_replace('/\s+/', ' ', $tester->getDisplay(true)) ?? '', $match));
        $run = self::getContainer()->get(JobMonitorAdministrationInterface::class)->job($match[1]);
        self::assertSame('BatchExtractCoversCommand', $run->name);
        self::assertSame(JobStatus::Finished, $run->status);
    }
}
