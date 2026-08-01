<?php

declare(strict_types=1);

namespace App\Radio\Interface\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateRadioSourceRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $name,

        #[Assert\NotBlank]
        public string $type,

        #[Assert\NotBlank]
        #[Assert\Url]
        public string $syncUrl,

        #[Assert\Type('array')]
        public array $syncConfig = [],

        public ?string $syncSchedule = null,
    ) {
    }
}
