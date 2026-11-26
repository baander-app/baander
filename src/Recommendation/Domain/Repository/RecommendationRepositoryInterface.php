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
     * Stores the recommendation unless a stored row has its source, target, name and user,
     * whoever wrote that row; the stored row stays as it is.
     *
     * @return bool whether the recommendation was stored
     */
    public function insertUnlessStored(Recommendation $recommendation): bool;

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
     * Deletes the source's rows the actor wrote as a member, or every row of the source when no
     * actor is given.
     */
    public function deleteBySource(string $sourceType, string $sourceId, ?Uuid $actorId = null): void;

    /**
     * Deletes the source's generator rows that belong to the user or to no user, which a run for
     * the user writes anew; a null user deletes only the rows of no user. Member rows stay.
     */
    public function deleteGeneratedBySource(string $sourceType, string $sourceId, ?Uuid $userId): void;

    public function delete(Recommendation $recommendation): void;
}
