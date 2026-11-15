<?php

declare(strict_types=1);

namespace App\Favorites\Infrastructure\Doctrine;

use App\Favorites\Application\Port\AlbumFavoriteMergePortInterface;
use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Favorites\Infrastructure\Doctrine\Entity\UserFavoriteEntity;
use App\Shared\Domain\Model\PublicId;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AlbumFavoriteMerge implements AlbumFavoriteMergePortInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function merge(PublicId $source, PublicId $target): void
    {
        $this->move(FavoriteType::Album, $source, $target);
    }

    #[\Override]
    public function foldSong(PublicId $duplicate, PublicId $kept): void
    {
        $this->move(FavoriteType::Song, $duplicate, $kept);
    }

    private function move(FavoriteType $type, PublicId $from, PublicId $to): void
    {
        // A user holds one favorite per album or song (uniq_user_favorites_user_id_entity_type_entity_public_id).
        // One statement takes each favorite of $from away and writes it back for $to, with its own
        // ID, public ID and times. ON CONFLICT drops it for a user who holds a favorite of $to, also
        // one another session has stored but not yet committed: the insert waits for that session
        // and yields once it commits. A delete of such favorites followed by an update would fail
        // on the key instead. SQL, because DQL has neither a data-modifying WITH nor ON CONFLICT.
        $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                WITH moved AS (
                    DELETE FROM user_favorites
                    WHERE entity_type = :type AND entity_public_id = :from
                    RETURNING id, public_id, user_id, entity_type, created_at, updated_at
                )
                INSERT INTO user_favorites (id, public_id, user_id, entity_type, entity_public_id, created_at, updated_at)
                SELECT id, public_id, user_id, entity_type, :to, created_at, updated_at FROM moved
                ON CONFLICT (user_id, entity_type, entity_public_id) DO NOTHING
                SQL,
            ['type' => $type->value, 'from' => $from->toString(), 'to' => $to->toString()],
        );
        $this->release($type, $from);
    }

    /**
     * The favorites this manager holds of $favorited no longer match their rows, or their rows are
     * gone; nothing refers to a favorite. One still to be loaded reads its row as the statement
     * left it.
     */
    private function release(FavoriteType $type, PublicId $favorited): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap()[UserFavoriteEntity::class] ?? [] as $favorite) {
            if ($favorite instanceof UserFavoriteEntity
                && !$unitOfWork->isUninitializedObject($favorite)
                && $favorite->getEntityType() === $type->value
                && $favorite->getEntityPublicId() === $favorited->toString()) {
                $this->entityManager->detach($favorite);
            }
        }
    }
}
