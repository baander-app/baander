<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole;

use App\QoL\Domain\Service\StreamGovernor;
use App\Transcode\Domain\Event\TranscodeSessionAttached;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Intercepts TranscodeSessionAttached at priority:1 (before TranscodeSessionSubscriber).
 * Evaluates budget and allocates stream. Throws StreamBudgetExhausted to veto.
 *
 * Reads the quality tier from the event itself (no DB access) and the hardware
 * acceleration flag from the resolved EncoderProfile (no DB access), so the
 * synchronous veto path never blocks the HTTP worker on a database query.
 */
final class SessionBudgetSubscriber
{
    public function __construct(
        private readonly StreamGovernor             $governor,
        private readonly HardwareCapabilitiesProber $prober,
        private readonly LoggerInterface            $logger,
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
        $hardwareAccelerated = $this->prober->getProfile()->isHardware();

        // Evaluate budget — may throw StreamBudgetExhausted (veto)
        $allowedTier = $this->governor->evaluateBudget(
            sourceHeight: 1080, // Default — probeData empty at session creation
            targetBitrate: $tier->videoBitrate,
            hardwareAccelerated: $hardwareAccelerated,
            requestedTier: $tier->name,
        );

        // Predict cost for allocation tracking
        $predictedCost = $this->governor->getModel()->predict(1080, $tier->videoBitrate, $hardwareAccelerated)
            ?? $this->governor->getModel()->averageCostForTier($tier->name)
            ?? 25.0;

        $this->governor->allocateStream($event->getJobId(), $allowedTier, $predictedCost);

        $this->logger->info('SessionBudget: allocated stream', [
            'jobId' => $event->getJobId()->toString(),
            'requestedTier' => $tier->name,
            'allowedTier' => $allowedTier,
            'predictedCost' => $predictedCost,
            'hardwareAccelerated' => $hardwareAccelerated,
        ]);
    }
}
