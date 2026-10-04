<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Activity\Domain\Model\MediaActivity;

final readonly class MediaActivityResultStamp implements ResultStampInterface
{
    public function __construct(
        private MediaActivity $activity,
    ) {
    }

    public static function fromResult(mixed $result): ?static
    {
        return $result instanceof MediaActivity ? new self($result) : null;
    }

    public function getActivity(): MediaActivity
    {
        return $this->activity;
    }
}
