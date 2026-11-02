<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterPlaybackEndpointRequest
{
    /** @param list<string> $capabilities */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $deviceId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $endpointId,
        #[Assert\NotBlank]
        #[Assert\Length(min: 1, max: 128)]
        public string $name = 'Browser',
        #[Assert\Count(min: 1, max: 3)]
        #[Assert\All(constraints: [new Assert\Choice(choices: ['song', 'video', 'volume'])])]
        public array $capabilities = ['song', 'video'],
        #[Assert\Uuid]
        public ?string $tabId = null,
        public bool $localRecoveryEligible = false,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
