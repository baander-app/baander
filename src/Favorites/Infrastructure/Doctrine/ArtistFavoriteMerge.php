<?php

declare(strict_types=1);

namespace App\Favorites\Infrastructure\Doctrine;

use App\Favorites\Application\Port\ArtistFavoriteMergePortInterface;
use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Favorites\Infrastructure\Doctrine\Entity\UserFavoriteEntity;
use App\Shared\Domain\Model\PublicId;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ArtistFavoriteMerge implements ArtistFavoriteMergePortInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function merge(PublicId $source, PublicId $target): void
    {
        // A user holds one favorite per artist (the unique key on user, type and public ID), so a
        // user who favorited both keeps the target's.
        $this->execute(
            'DELETE ' . UserFavoriteEntity::class . ' favorite WHERE favorite.entityType = :artist AND favorite.entityPublicId = :source'
            . ' AND EXISTS (SELECT kept.id FROM ' . UserFavoriteEntity::class . ' kept'
            . ' WHERE kept.userId = favorite.userId AND kept.entityType = :artist AND kept.entityPublicId = :target)',
            ['source' => $source->toString(), 'target' => $target->toString()],
        );
        $this->execute(
            'UPDATE ' . UserFavoriteEntity::class . ' favorite SET favorite.entityPublicId = :target'
            . ' WHERE favorite.entityType = :artist AND favorite.entityPublicId = :source',
            ['source' => $source->toString(), 'target' => $target->toString()],
        );
        $this->release($source);
    }

    #[\Override]
    public function remove(PublicId $artist): void
    {
        $this->execute(
            'DELETE ' . UserFavoriteEntity::class . ' favorite WHERE favorite.entityType = :artist AND favorite.entityPublicId = :favorited',
            ['favorited' => $artist->toString()],
        );
        $this->release($artist);
    }

    /** @param array<string, string> $parameters */
    private function execute(string $dql, array $parameters): void
    {
        $query = $this->entityManager->createQuery($dql)->setParameter('artist', FavoriteType::Artist->value);
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }
        $query->execute();
    }

    /** The favorites this manager holds of the artist no longer match their rows; nothing refers to them. */
    private function release(PublicId $artist): void
    {
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[UserFavoriteEntity::class] ?? [] as $favorite) {
            if ($favorite instanceof UserFavoriteEntity
                && $favorite->getEntityType() === FavoriteType::Artist->value
                && $favorite->getEntityPublicId() === $artist->toString()) {
                $this->entityManager->detach($favorite);
            }
        }
    }
}
