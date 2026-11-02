<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Catalog\Application\Port\VideoPortInterface;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Application\Port\EncodePresentationPort;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Domain\ValueObject\PresentationSelection;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\FFmpeg\EncodePresentationIdentity;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\FFmpeg\WorkerEncoderCapabilitiesSnapshotStore;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class EncodePresentationAdapter implements EncodePresentationPort
{
    public function __construct(
        private VideoPortInterface $videos,
        private WorkerEncoderCapabilitiesSnapshotStore $snapshots,
        private MediaExecutionPolicy $policy,
        #[Autowire(lazy: true)] private FFmpegPortInterface $ffmpeg,
        private TranscodeStoragePortInterface $storage,
    ) {
    }

    public function select(CreateTranscodeSessionCommand $command): PresentationSelection
    {
        $video = $this->videos->findByUuid($command->getVideoId());
        $snapshot = $this->snapshots->read();
        if ($video === null || $snapshot === null || $video->getProbe() === []) {
            throw new TranscodeStartupUnavailableException();
        }
        $encoder = new SegmentEncoder($this->policy, $this->ffmpeg, $this->storage, $snapshot->profile, $snapshot->bitrateMultiplier);
        return EncodePresentationIdentity::derive(
            $video->getHash(),
            VideoProbeResult::fromSerialized($video->getProbe()),
            QualityTier::fromString($command->getQualityTier()->name),
            $command->getAudioProfile(),
            array_values($command->getAudioLanguages()),
            $encoder,
        );
    }
}
