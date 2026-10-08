<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\LoginBlock;

use App\Auth\Application\Command\LoginBlock\DeleteAllLoginBlocksCommand;
use App\Auth\Domain\Repository\LoginBlockRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class DeleteAllLoginBlocksHandler
{
    public function __construct(
        private LoginBlockRepositoryInterface $blocks,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(DeleteAllLoginBlocksCommand $command): void
    {
        $this->blocks->deleteAll();
    }
}
