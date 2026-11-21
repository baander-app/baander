<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Person;

use App\Catalog\Application\Command\Person\ManagePersonCommand;
use App\Catalog\Application\Port\PersonAdministrationPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ManagePersonHandler
{
    public function __construct(private PersonAdministrationPortInterface $people) {}

    /** @return array<string, mixed> */
    public function __invoke(ManagePersonCommand $command): array
    {
        return $this->people->execute($command);
    }
}
