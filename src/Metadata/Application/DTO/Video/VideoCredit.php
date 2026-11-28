<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\Video;

final readonly class VideoCredit
{
    /**
     * @param array<string, string> $identifiers person IDs keyed by namespace
     */
    public function __construct(
        public array $identifiers,
        public string $name,
        public string $category,
        public string $role,
        public string $job,
        public ?string $characterName = null,
        public ?string $department = null,
        public ?string $creditedName = null,
        public ?int $billingOrder = null,
        public ?string $providerCreditId = null,
        public ?string $profileUrl = null,
    ) {}
}
