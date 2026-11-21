<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Person;

final readonly class ManagePersonCommand
{
    /** @param array<string, mixed> $profile */
    public function __construct(
        public string $action,
        public ?string $person,
        public ?string $value,
        public ?string $namespace,
        public array $profile,
        public bool $preferTarget = false,
    ) {}
}
