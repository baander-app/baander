<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\VideoMatch;

use App\Metadata\Application\DTO\Video\VideoProviderId;
use DateTimeImmutable;

final readonly class VideoMatchOutcome
{
    public function __construct(
        public VideoMatchOutcomeKind $kind,
        public ?VideoProviderId $provider = null,
        public ?string $reason = null,
        public ?int $score = null,
        public ?DateTimeImmutable $retryAt = null,
    ) {}
}
