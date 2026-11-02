<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\CompletedVideoArtifact;

interface CompletedVideoArtifactPort
{
    /**
     * Return only current, completely published artifacts for this source and owner.
     * Missing files, partial generations and unverifiable source identities are misses.
     * This lookup must never admit, reset or schedule work.
     *
     * @return list<CompletedVideoArtifact>
     */
    public function findComplete(Uuid $owner, Uuid $video, string $sourceHash, int $videoStreamIndex, ?int $audioStreamIndex): array;
}
