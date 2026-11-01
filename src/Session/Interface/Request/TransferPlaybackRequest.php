<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TransferPlaybackRequest
{
    public function __construct(
        #[Assert\Uuid]
        public string $requestId,
        #[Assert\PositiveOrZero]
        public int $expectedRevision,
        #[Assert\Uuid]
        public string $targetIncarnationId,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
