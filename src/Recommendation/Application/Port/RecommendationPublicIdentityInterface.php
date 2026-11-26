<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Port;

use App\Recommendation\Domain\Model\Recommendation;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;

interface RecommendationPublicIdentityInterface
{
    /**
     * @return array{public_id: string, name: string, source_type: string, source_id: string, target_type: string, target_id: string, score: float, position: int|null, user_id: string|null, created_at: string, updated_at: string}|null Null when an owner or related entity is inaccessible.
     */
    public function project(Recommendation $recommendation, Uuid $actorId, bool $administrator): ?array;

    /**
     * The row with the public source and target identities its writer addressed it by. Visibility
     * is not checked again, so access revoked after the write cannot empty the answer to it.
     *
     * @return array{public_id: string, name: string, source_type: string, source_id: string, target_type: string, target_id: string, score: float, position: int|null, user_id: string|null, created_at: string, updated_at: string}
     *
     * @throws NotFoundException when the row's user no longer exists
     */
    public function projectAsAddressed(Recommendation $recommendation, string $sourcePublicId, string $targetPublicId): array;

    public function internalEntityId(string $type, string $publicId, Uuid $actorId, bool $administrator): Uuid;

    public function publicEntityId(string $type, string $internalId, Uuid $actorId, bool $administrator): ?string;

    public function recommendationId(string $publicId, Uuid $actorId, bool $administrator): Uuid;
}
