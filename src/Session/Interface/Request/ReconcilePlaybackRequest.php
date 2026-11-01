<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReconcilePlaybackRequest
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public int $expectedRevision,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
