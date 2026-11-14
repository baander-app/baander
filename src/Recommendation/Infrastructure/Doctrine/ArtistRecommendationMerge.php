<?php

declare(strict_types=1);

namespace App\Recommendation\Infrastructure\Doctrine;

use App\Recommendation\Application\Port\ArtistRecommendationMergePortInterface;
use App\Recommendation\Infrastructure\Doctrine\Entity\RecommendationEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recommendations name their source and target by type and internal ID, so an artist's are the
 * rows with "artist" and its ID on either side.
 */
final readonly class ArtistRecommendationMerge implements ArtistRecommendationMergePortInterface
{
    private const string ARTIST = 'artist';

    /**
     * Two rows recommend the same thing when they share both ends, the name and the user; the
     * unique key treats a missing user as equal to another missing user.
     */
    private const string SAME_RECOMMENDATION = 'kept.name = rec.name AND (kept.user = rec.user OR (kept.user IS NULL AND rec.user IS NULL))';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function merge(Uuid $source, Uuid $target): void
    {
        $ids = ['source' => $source->toString(), 'target' => $target->toString()];

        // A recommendation between the two artists would recommend the target to itself.
        $this->execute(
            'DELETE ' . RecommendationEntity::class . ' rec WHERE rec.sourceType = :artist AND rec.targetType = :artist'
            . ' AND rec.sourceId IN (:pair) AND rec.targetId IN (:pair) AND (rec.sourceId = :source OR rec.targetId = :source)',
            ['source' => $ids['source'], 'pair' => array_values($ids)],
        );
        // Each end moves after dropping the rows the target already has at that end.
        $this->execute(
            'DELETE ' . RecommendationEntity::class . ' rec WHERE rec.sourceType = :artist AND rec.sourceId = :source'
            . ' AND EXISTS (SELECT kept.id FROM ' . RecommendationEntity::class . ' kept WHERE kept.sourceType = :artist AND kept.sourceId = :target'
            . ' AND kept.targetType = rec.targetType AND kept.targetId = rec.targetId AND ' . self::SAME_RECOMMENDATION . ')',
            $ids,
        );
        $this->execute(
            'UPDATE ' . RecommendationEntity::class . ' rec SET rec.sourceId = :target WHERE rec.sourceType = :artist AND rec.sourceId = :source',
            $ids,
        );
        $this->execute(
            'DELETE ' . RecommendationEntity::class . ' rec WHERE rec.targetType = :artist AND rec.targetId = :source'
            . ' AND EXISTS (SELECT kept.id FROM ' . RecommendationEntity::class . ' kept WHERE kept.targetType = :artist AND kept.targetId = :target'
            . ' AND kept.sourceType = rec.sourceType AND kept.sourceId = rec.sourceId AND ' . self::SAME_RECOMMENDATION . ')',
            $ids,
        );
        $this->execute(
            'UPDATE ' . RecommendationEntity::class . ' rec SET rec.targetId = :target WHERE rec.targetType = :artist AND rec.targetId = :source',
            $ids,
        );
        $this->release($source);
    }

    #[\Override]
    public function remove(Uuid $artist): void
    {
        $this->execute(
            'DELETE ' . RecommendationEntity::class . ' rec'
            . ' WHERE (rec.sourceType = :artist AND rec.sourceId = :source) OR (rec.targetType = :artist AND rec.targetId = :source)',
            ['source' => $artist->toString()],
        );
        $this->release($artist);
    }

    /** @param array<string, string|list<string>> $parameters */
    private function execute(string $dql, array $parameters): void
    {
        $query = $this->entityManager->createQuery($dql)->setParameter('artist', self::ARTIST);
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }
        $query->execute();
    }

    /** The recommendations this manager holds from or to the artist no longer match their rows; nothing refers to them. */
    private function release(Uuid $artist): void
    {
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[RecommendationEntity::class] ?? [] as $recommendation) {
            if ($recommendation instanceof RecommendationEntity && self::names($recommendation, $artist)) {
                $this->entityManager->detach($recommendation);
            }
        }
    }

    private static function names(RecommendationEntity $recommendation, Uuid $artist): bool
    {
        return ($recommendation->getSourceType() === self::ARTIST && $recommendation->getSourceId() === $artist->toString())
            || ($recommendation->getTargetType() === self::ARTIST && $recommendation->getTargetId() === $artist->toString());
    }
}
