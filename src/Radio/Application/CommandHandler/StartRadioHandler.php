<?php

declare(strict_types=1);

namespace App\Radio\Application\CommandHandler;

use App\Radio\Application\Command\StartRadioCommand;
use App\Radio\Application\Port\RadioSessionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StartRadioHandler
{
    public function __construct(
        private readonly RadioSessionPortInterface $sessionPort,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(StartRadioCommand $command): array
    {
        return $this->sessionPort->startRadio(
            userId: $command->getUserId(),
            stationId: $command->getStationId(),
            streamUrl: $command->getStreamUrl(),
        );
    }
}
