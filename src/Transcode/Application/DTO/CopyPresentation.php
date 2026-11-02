<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

use App\Shared\Domain\Model\PublicId;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class CopyPresentation
{
    public function __construct(public PublicId $jobId, public PublicId $sessionId, public string $requestId)
    {
    }
}
