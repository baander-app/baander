<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Application\DTO\HardwareTranscodeDiagnosticsReport;
use App\Transcode\Application\Port\HardwareTranscodeDiagnosticsInterface;
use App\Transcode\Domain\Service\VideoProcessingRules;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use App\Transcode\Domain\ValueObject\QualityTier;

final readonly class HardwareTranscodeDiagnostics implements HardwareTranscodeDiagnosticsInterface
{
    public function __construct(private HardwareCapabilitiesProber $prober)
    {
    }

    public function inspect(): HardwareTranscodeDiagnosticsReport
    {
        $this->prober->boot();
        $profile = $this->prober->getProfile();
        $multiplier = $this->prober->getBitrateMultiplier();

        $sourceDecoders = [];
        if ($profile->isHardware()) {
            foreach (['h264', 'hevc', 'av1', 'mpeg2video', 'vp9'] as $codec) {
                $sourceDecoders[$codec] = $profile->withDecoderForSource($codec)->decoder;
            }
        }

        $tiers = [QualityTier::p720(), QualityTier::p1080(), QualityTier::p4K()];

        $sampleCommands = [];
        foreach ($tiers as $tier) {
            $resolvedProfile = $profile->withDecoderForSource('h264');
            $encoderFlags = VideoProcessingRules::codecFlags($profile->encoder);
            $hwAccelFlags = $resolvedProfile->hwaccelInputFlags();
            $decoderFlags = $resolvedProfile->decoderFlags();

            $cmd = sprintf(
                '%s -y %s%s -i source.mkv %s'
                . ' -b:v %d -maxrate %d -bufsize %d'
                . ' -movflags +frag_keyframe+separate_moof+default_base_moof'
                . ' -an -f mp4 %s',
                '/usr/local/bin/ffmpeg',
                $hwAccelFlags !== '' ? $hwAccelFlags . ' ' : '',
                $decoderFlags !== '' ? $decoderFlags . ' ' : '',
                $encoderFlags,
                $tier->videoBitrate,
                $tier->maxBitrate,
                $tier->bufferSize,
                escapeshellarg(sprintf('/tmp/init_%s.mp4', $tier->name)),
            );

            $sampleCommands[$tier->name] = $cmd;
        }

        $refRows = [];
        foreach (HardwareAccelerator::cases() as $case) {
            $refRows[] = [
                $case->value,
                $case->hevcEncoder(),
                $case->h264Encoder(),
                $case->ffmpegHwaccelMethod() ?: '(none)',
                $case->supportsHardwareTonemap() ? 'Yes' : 'No',
                $case->requiresDevicePath() ? 'Yes' : 'No',
            ];
        }

        return new HardwareTranscodeDiagnosticsReport(
            accelerator: $profile->accelerator->value,
            encoder: $profile->encoder,
            decoder: $profile->decoder,
            hwaccelMethod: $profile->hwaccelMethod,
            hwaccelDevice: $profile->hwaccelDevice,
            hwaccelOutputFormat: $profile->hwaccelOutputFormat,
            isHardware: $profile->isHardware(),
            bitrateMultiplier: $multiplier,
            hwaccelFlags: $profile->hwaccelInputFlags(),
            decoderFlags: $profile->decoderFlags(),
            sourceDecoders: $sourceDecoders,
            sampleCommands: $sampleCommands,
            acceleratorReference: $refRows,
        );
    }
}
