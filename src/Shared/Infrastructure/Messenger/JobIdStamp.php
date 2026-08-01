<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Domain\Model\PublicId;
use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class JobIdStamp implements StampInterface
{
    public function __construct(
        public PublicId $jobId,
    ) {
    }
}
