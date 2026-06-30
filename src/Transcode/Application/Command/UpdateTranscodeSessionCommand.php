<?php

declare(strict_types=1);

namespace App\Transcode\Application\Command;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\ValueObject\AudioProfile;

final readonly class UpdateTranscodeSessionCommand
{
    public function __construct(
        public Uuid $sessionId,
        public ?AudioProfile $audioProfile = null,
    ) {}
}
