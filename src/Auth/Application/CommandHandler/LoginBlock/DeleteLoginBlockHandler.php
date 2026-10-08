<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\LoginBlock;

use App\Auth\Application\Command\LoginBlock\DeleteLoginBlockCommand;
use App\Auth\Application\Exception\LoginBlockNotFoundException;
use App\Auth\Domain\Repository\LoginBlockRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class DeleteLoginBlockHandler
{
    public function __construct(
        private LoginBlockRepositoryInterface $blocks,
    ) {
    }

    /** @throws LoginBlockNotFoundException when the ID is malformed or no block has it */
    #[AsMessageHandler]
    public function __invoke(DeleteLoginBlockCommand $command): void
    {
        try {
            $id = Uuid::fromString($command->id);
        } catch (\InvalidArgumentException $exception) {
            throw LoginBlockNotFoundException::forId($command->id, $exception);
        }

        if (!$this->blocks->deleteByUuid($id)) {
            throw LoginBlockNotFoundException::forId($command->id);
        }
    }
}
