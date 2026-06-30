<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Activity\Domain\Model\MediaActivity;
use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class MediaActivityResultStamp implements StampInterface
{
    public function __construct(
        private MediaActivity $activity,
    ) {
    }

    public static function fromResult(mixed $result): ?self
    {
        return $result instanceof MediaActivity ? new self($result) : null;
    }

    public function getActivity(): MediaActivity
    {
        return $this->activity;
    }
}
