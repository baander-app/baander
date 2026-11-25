<?php

declare(strict_types=1);

namespace App\Recommendation\Domain\Repository;

use App\Recommendation\Domain\Model\Recommendation;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;

interface RecommendationRepositoryInterface
{
    public function save(Recommendation $recommendation): void;

    /**
     * @param Recommendation[] $recommendations
     */
    public function saveMany(array $recommendations): void;

    /**
     * Makes the user's member-written recommendations take turns until the caller's transaction
     * ends. Requires an open transaction.
     */
    public function lockMemberRecommendations(Uuid $userId): void;

    /** The number of rows the user wrote as a member, as committed when the statement runs. */
    public function countWrittenByMember(Uuid $userId): int;

    /** Whether a stored row has this recommendation's source, target, name and user. */
    public function hasDuplicateOf(Recommendation $recommendation): bool;

    public function findByUuid(Uuid $uuid): ?Recommendation;

    public function findByPublicId(PublicId $publicId): ?Recommendation;

    /**
     * Get recommendations for a given source entity, best score first: the viewer's rows and the
     * rows that belong to no user, or every user's rows when the viewer is null.
     *
     * @return Recommendation[]
     */
    public function findBySource(string $sourceType, string $sourceId, ?Uuid $viewerId, string $name = 'default', int $limit = 100): array;

    /**
     * Get recommendations targeting a specific entity (e.g., songs recommended for a song), best
     * score first: the viewer's rows and the rows that belong to no user, or every user's rows
     * when the viewer is null.
     *
     * @return Recommendation[]
     */
    public function findTargeting(string $targetType, string $targetId, ?Uuid $viewerId, int $limit = 100): array;

    /**
     * Get personalized recommendations for a user.
     *
     * @return Recommendation[]
     */
    public function findForUser(Uuid $userId, int $limit = 50): array;

    /**
     * Delete all recommendations for a given source.
     */
    public function deleteBySource(string $sourceType, string $sourceId, ?Uuid $actorId = null): void;

    public function delete(Recommendation $recommendation): void;
}
