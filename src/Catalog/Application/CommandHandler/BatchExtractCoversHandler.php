<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final class BatchExtractCoversHandler
{
    public function __construct(
        private readonly AlbumRepositoryInterface $albumRepository,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler(fromTransport: 'swoole_task')]
    public function __invoke(BatchExtractCoversCommand $command): int
    {
        $limit = 500;
        $offset = 0;
        $dispatched = 0;

        while (true) {
            $ids = $this->albumRepository->findCoverlessAlbumIds($limit, $offset);

            if ($ids === []) {
                break;
            }

            foreach ($ids as $albumId) {
                try {
                    $this->bus->dispatch(new ExtractAlbumCoverCommand($albumId));
                    ++$dispatched;
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to dispatch cover extraction for album {id}', [
                        'id' => $albumId->toString(),
                        'error' => $e->getMessage(),
                        'dispatched' => $dispatched,
                    ]);
                }
            }

            $offset += $limit;
        }

        $this->logger->info('Batch cover extraction completed', [
            'dispatched' => $dispatched,
        ]);

        return $dispatched;
    }
}
