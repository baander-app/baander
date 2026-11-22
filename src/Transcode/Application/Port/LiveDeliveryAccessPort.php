<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Application\Exception\ConflictException;
use App\Shared\Domain\Model\PublicId;

/**
 * Live HLS and DASH delivery serves a signed URL only while the owner of each job it names
 * may still play the job's video. Revoked library access, a disabled owner and a job that no
 * longer exists all refuse alike, so the answer does not reveal which one applied.
 */
interface LiveDeliveryAccessPort
{
    /** @throws ConflictException when the job's owner may no longer play its video */
    public function assertOwnerMayStream(PublicId $jobId): void;

    /**
     * @param list<PublicId> $jobIds the jobs a signed rendition set names
     *
     * @throws ConflictException when the owner of any of the jobs may no longer play its video
     */
    public function assertOwnersMayStream(array $jobIds): void;
}
