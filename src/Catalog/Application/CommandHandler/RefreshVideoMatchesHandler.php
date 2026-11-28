<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\RefreshVideoMatchesCommand;
use App\Catalog\Application\Port\VideoMatchRefreshInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class RefreshVideoMatchesHandler
{
    public function __construct(private VideoMatchRefreshInterface $refresh) {}

    #[AsMessageHandler]
    public function __invoke(RefreshVideoMatchesCommand $command): int
    {
        return $this->refresh->requestAll();
    }
}
