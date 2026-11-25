<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * A transcode session that counts toward its user's share: it is not ended and still holds its
 * job's execution, which runs in a video slot, waits for one or has finished.
 */
final readonly class HeldTranscodeSession
{
    public function __construct(
        public Uuid $sessionId,
        public Uuid $jobId,
        public Uuid $videoId,
        public string $requestId,
        public string $tier,
        /** The last playback report, control, stream signing or readiness that touched the session. */
        public DateTimeImmutable $lastSignOfLife,
        /** Whether its execution runs in a video slot or waits for one; a finished one holds none. */
        public bool $holdsSlot,
    ) {
    }

    /** Whether the session belongs to the client playback a start continues: the same video and client session. */
    public function belongsTo(Uuid $videoId, string $requestId): bool
    {
        return $this->videoId->equals($videoId) && $this->requestId === $requestId;
    }
}
