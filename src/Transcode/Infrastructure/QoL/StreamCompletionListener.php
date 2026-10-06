<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\QoL;

use App\QoL\Application\Port\StreamAdmissionPortInterface;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Domain\Event\TranscodeJobCompleted;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Reports each completed transcode job to QoL so it records a learning sample
 * and releases the job's stream allocation.
 *
 * Loads the job for its probe data and tier, so it uses CoWrapper::go() when
 * available to avoid blocking the HTTP worker on database access.
 */
final readonly class StreamCompletionListener
{
    public function __construct(
        private StreamAdmissionPortInterface $admission,
        private TranscodeJobPortInterface    $jobPort,
        private HardwareCapabilitiesProber   $prober,
        private LoggerInterface              $logger,
        private ?CoWrapper                   $coWrapper = null,
    )
    {
    }

    #[AsEventListener(event: TranscodeJobCompleted::class)]
    public function __invoke(TranscodeJobCompleted $event): void
    {
        if ($this->coWrapper !== null) {
            $this->coWrapper->go(function () use ($event): void {
                $this->complete($event);
            });

            return;
        }

        $this->complete($event);
    }

    private function complete(TranscodeJobCompleted $event): void
    {
        $job = $this->jobPort->findByUuid($event->getJobId());
        if ($job === null) {
            $this->logger->warning('StreamCompletion: job not found', [
                'jobId' => $event->getJobId()->toString(),
            ]);

            return;
        }

        $probeData = $job->getProbeData();
        $tier = QualityTier::fromString($job->getQualityTierName());

        $this->admission->complete(
            jobId: $event->getJobId(),
            qualityTier: $tier->name,
            targetBitrate: $tier->videoBitrate,
            sourceHeight: (int)($probeData['videoStreams'][0]['height'] ?? 0),
            sourceCodec: (string)($probeData['videoStreams'][0]['codecName'] ?? ''),
            hardwareAccelerated: $this->prober->getProfile()->isHardware(),
        );
    }
}
