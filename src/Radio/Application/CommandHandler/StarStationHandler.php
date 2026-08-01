<?php

declare(strict_types=1);

namespace App\Radio\Application\CommandHandler;

use App\Radio\Application\Command\StarStationCommand;
use App\Radio\Application\Port\StarredStationPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StarStationHandler
{
    public function __construct(
        private readonly StarredStationPortInterface $starredPort,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(StarStationCommand $command): array
    {
        return $this->starredPort->star(
            userId: $command->getUserId(),
            stationId: $command->getStationId(),
        );
    }
}
