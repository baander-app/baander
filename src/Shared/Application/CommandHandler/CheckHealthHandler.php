<?php

declare(strict_types=1);

namespace App\Shared\Application\CommandHandler;

use App\Shared\Application\Command\CheckHealthCommand;
use App\Shared\Application\Port\HealthAlertPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class CheckHealthHandler
{
    public function __construct(
        private HealthAlertPortInterface $healthAlerts,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(CheckHealthCommand $command): void
    {
        $this->healthAlerts->checkAndAlert();
    }
}
