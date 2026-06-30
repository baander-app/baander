<?php

declare(strict_types=1);

namespace App\Radio\Interface\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class StartRadioRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $stationId,

        #[Assert\NotBlank]
        #[Assert\Url]
        public string $streamUrl,
    ) {
    }
}
