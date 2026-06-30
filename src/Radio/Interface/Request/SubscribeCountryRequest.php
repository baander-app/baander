<?php

declare(strict_types=1);

namespace App\Radio\Interface\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SubscribeCountryRequest
{
    public function __construct(
        #[Assert\Uuid]
        public ?string $sourceId = null,

        #[Assert\NotBlank]
        #[Assert\Length(exactly: 2)]
        public string $countryCode = '',
    ) {
    }
}
