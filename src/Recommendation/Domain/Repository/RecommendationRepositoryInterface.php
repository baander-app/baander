<?php

declare(strict_types=1);

namespace App\Recommendation\Domain\Repository;

use App\Recommendation\Domain\Model\Recommendation;
use App\Shared\Domain\Model\Uuid;

interface RecommendationRepositoryInterface
{
    public function save(Recommendation $recommendation): void;

    /**
     * @param Recommendation[] $recommendations
     */
    public function saveMany(array $recommendations): void;

    public function findByUuid(Uuid $uuid): ?Recommendation;

    /**
     * Get recommendations for a given source entity.
     *
     * @return Recommendation[]
     */
    public function findBySource(string $sourceType, string $sourceId, string $name = 'default', int $limit = 100): array;

    /**
     * Get recommendations targeting a specific entity (e.g., songs recommended for a song).
     *
     * @return Recommendation[]
     */
    public function findTargeting(string $targetType, string $targetId, int $limit = 100): array;

    /**
     * Get personalized recommendations for a user.
     *
     * @return Recommendation[]
     */
    public function findForUser(Uuid $userId, int $limit = 50): array;

    /**
     * Delete all recommendations for a given source.
     */
    public function deleteBySource(string $sourceType, string $sourceId): void;

    public function delete(Recommendation $recommendation): void;
}
