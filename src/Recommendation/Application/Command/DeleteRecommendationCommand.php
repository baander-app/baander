<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Command;

use App\Recommendation\Domain\Model\Recommendation;
use App\Shared\Domain\Model\Uuid;

final readonly class DeleteRecommendationCommand
{
    public function __construct(
        private readonly Uuid $id,
    ) {
    }

    public function getRecommendation(): Recommendation
    {
        return Recommendation::reconstitute(
            $this->id,
            'delete-command',
            'song',
            'deleted',
            'song',
            'deleted',
            0.0,
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }
}
