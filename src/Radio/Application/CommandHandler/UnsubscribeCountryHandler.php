<?php

declare(strict_types=1);

namespace App\Radio\Application\CommandHandler;

use App\Radio\Application\Command\UnsubscribeCountryCommand;
use App\Radio\Application\Port\CountrySubscriptionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UnsubscribeCountryHandler
{
    public function __construct(
        private readonly CountrySubscriptionPortInterface $subscriptionPort,
    ) {
    }

    public function __invoke(UnsubscribeCountryCommand $command): void
    {
        $this->subscriptionPort->unsubscribe(
            userId: $command->getUserId(),
            sourceId: $command->getSourceId(),
            countryCode: $command->getCountryCode(),
        );
    }
}
