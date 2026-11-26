<?php

declare(strict_types=1);

namespace App\Transcode\Application\Service;

use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Domain\Model\TranscodeSession;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ends transcode sessions whose client can no longer be playing them.
 *
 * A client that leaves without cancelling keeps its session, and the session
 * keeps its job: the worker holds the execution at the lead throttle and renews
 * its lease for as long as the session exists. Expiry cancels the execution so
 * the worker lets go, ends the session and releases the job, which job cleanup
 * can then remove with its output.
 */
final readonly class StaleTranscodeSessionExpiry
{
    /**
     * The longest lifetime of a signed stream URL. Every playback report and
     * every signing touches the session, so a session untouched for this long
     * has no client holding a URL that still plays. A paused player sends no
     * reports but renews its URL before it ends, and so never expires.
     */
    public const int IDLE_SECONDS = 86400;

    public function __construct(
        private TranscodeSessionPortInterface $sessionPort,
        private TranscodeSessionControl $control,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /** @return int The number of sessions that expired */
    public function expire(): int
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d seconds', self::IDLE_SECONDS));
        $expired = 0;
        foreach ($this->sessionPort->findActive() as $candidate) {
            if ($candidate->getUpdatedAt() > $cutoff) {
                continue;
            }
            try {
                // A client that reported or signed since the read keeps its session. A finished
                // presentation keeps its own: it costs no worker and is not orphaned output, and the
                // same client resumes it without a new encode.
                $stays = static fn (TranscodeSession $current, bool $finished): bool => $finished || $current->getUpdatedAt() > $cutoff;
                if ($this->control->end($candidate, $stays)) {
                    ++$expired;
                }
            } catch (Throwable $failure) {
                // One session that cannot expire must not keep the others, or the
                // job cleanup that follows, from running; the next run tries it again.
                $this->logger->error('A stale transcode session could not be expired.', [
                    'session' => $candidate->getId()->toString(),
                    'exception' => $failure,
                ]);
            }
        }

        return $expired;
    }
}
