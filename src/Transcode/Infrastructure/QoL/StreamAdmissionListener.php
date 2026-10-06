<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\QoL;

use App\QoL\Application\Port\StreamAdmissionPortInterface;
use App\Transcode\Domain\Event\TranscodeSessionAttached;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Submits each attached transcode session to QoL stream admission.
 *
 * Runs synchronously at priority 1. Both dispatchers (CreateTranscodeSessionHandler
 * and GracefulRestartHandler) dispatch the event before starting the encoding loop,
 * so a budget veto thrown by admission propagates, the loop never starts and the
 * loop lease is released.
 *
 * Reads the tier from the event and the hardware flag from the resolved encoder
 * profile; it performs no database access.
 */
final readonly class StreamAdmissionListener
{
    /** Probe data is empty at session creation, so admission assumes a 1080p source. */
    private const int SOURCE_HEIGHT = 1080;

    public function __construct(
        private StreamAdmissionPortInterface $admission,
        private HardwareCapabilitiesProber   $prober,
    )
    {
    }

    #[AsEventListener(event: TranscodeSessionAttached::class, priority: 1)]
    public function __invoke(TranscodeSessionAttached $event): void
    {
        $tierName = $event->getQualityTier();
        if ($tierName === '') {
            return;
        }

        $tier = QualityTier::fromString($tierName);

        $this->admission->admit(
            jobId: $event->getJobId(),
            qualityTier: $tier->name,
            targetBitrate: $tier->videoBitrate,
            sourceHeight: self::SOURCE_HEIGHT,
            hardwareAccelerated: $this->prober->getProfile()->isHardware(),
        );
    }
}
