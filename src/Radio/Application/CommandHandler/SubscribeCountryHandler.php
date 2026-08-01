<?php

declare(strict_types=1);

namespace App\Radio\Application\CommandHandler;

use App\Radio\Application\Command\SubscribeCountryCommand;
use App\Radio\Application\Port\CountrySubscriptionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SubscribeCountryHandler
{
    public function __construct(
        private readonly CountrySubscriptionPortInterface $subscriptionPort,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(SubscribeCountryCommand $command): array
    {
        return $this->subscriptionPort->subscribe(
            userId: $command->getUserId(),
            sourceId: $command->getSourceId(),
            countryCode: $command->getCountryCode(),
        );
    }
}
