<?php

declare(strict_types=1);

namespace App\Radio\Interface\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class StarStationRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(exactly: 21)]
        #[Assert\Regex(pattern: '/^[0-9a-zA-Z_-]{21}$/D')]
        public string $stationId,
    ) {
    }
}
