<?php

declare(strict_types=1);

namespace App\Favorites\Application\CommandHandler;

use App\Favorites\Application\Command\RemoveFavoriteCommand;
use App\Favorites\Application\Port\FavoritesPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class RemoveFavoriteHandler
{
    public function __construct(
        private readonly FavoritesPortInterface $favoritesPort,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(RemoveFavoriteCommand $command): void
    {
        $favorite = $this->favoritesPort->findByPublicId($command->getPublicId());
        if ($favorite === null) {
            return;
        }

        if (!$favorite->getUserId()->equals($command->getUserId())) {
            return;
        }

        $this->favoritesPort->removeFavorite($favorite);
    }
}
