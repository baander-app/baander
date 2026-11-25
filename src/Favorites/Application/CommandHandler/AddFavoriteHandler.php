<?php

declare(strict_types=1);

namespace App\Favorites\Application\CommandHandler;

use App\Favorites\Application\Command\AddFavoriteCommand;
use App\Favorites\Application\Port\FavoritesPortInterface;
use App\Favorites\Application\Port\FavoriteTargetAccessInterface;
use App\Favorites\Domain\Model\UserFavorite;
use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Shared\Domain\Model\PublicId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class AddFavoriteHandler
{
    public function __construct(
        private readonly FavoritesPortInterface $favoritesPort,
        private readonly FavoriteTargetAccessInterface $targets,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(AddFavoriteCommand $command): UserFavorite
    {
        $entityType = FavoriteType::from($command->getEntityType());
        $publicId = PublicId::fromString($command->getEntityPublicId());
        $this->targets->assertVisible($command->getUserId(), $entityType, $publicId);

        $existing = $this->favoritesPort->findByUserAndEntity(
            $command->getUserId(),
            $entityType,
            $command->getEntityPublicId(),
        );

        if ($existing !== null) {
            return $existing;
        }

        return $this->favoritesPort->addFavorite(
            $command->getUserId(),
            $entityType,
            $command->getEntityPublicId(),
        );
    }
}
