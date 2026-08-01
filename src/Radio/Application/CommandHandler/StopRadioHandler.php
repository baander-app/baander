<?php

declare(strict_types=1);

namespace App\Radio\Application\CommandHandler;

use App\Radio\Application\Command\StopRadioCommand;
use App\Radio\Application\Port\RadioSessionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StopRadioHandler
{
    public function __construct(
        private readonly RadioSessionPortInterface $sessionPort,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(StopRadioCommand $command): array
    {
        return $this->sessionPort->stopRadio(
            userId: $command->getUserId(),
        );
    }
}
