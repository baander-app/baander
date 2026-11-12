<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class OverridePlaybackRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $requestId,
        #[Assert\PositiveOrZero]
        public int $expectedRevision,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $incarnationId,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
