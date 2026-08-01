<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Console;

use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\Service\VideoProcessingRules;
use App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Debug command for verifying hardware transcoding configuration.
 *
 * Boots the HardwareCapabilitiesProber, resolves the active EncoderProfile,
 * and dumps the resulting hwaccel flags, decoder flags, and sample FFmpeg
 * commands for each quality tier. Use on-metal to verify GPU detection.
 *
 * Usage: php bin/console debug:hw-transcode
 */
#[AsCommand(
    name: 'debug:hw-transcode',
    description: 'Show resolved hardware encoder profile and sample FFmpeg commands.',
)]
final class DebugHwTranscodeCommand extends Command
{
    public function __construct(
        private readonly HardwareCapabilitiesProber $prober,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Boot the prober (idempotent if already booted by Swoole)
        $this->prober->boot();

        $profile = $this->prober->getProfile();
        $multiplier = $this->prober->getBitrateMultiplier();

        // --- Profile summary ---
        $io->section('Resolved Encoder Profile');
        $io->table(
            ['Property', 'Value'],
            [
                ['Accelerator', $profile->accelerator->value],
                ['Encoder', $profile->encoder],
                ['Decoder', $profile->decoder ?: '(per-source)'],
                ['HWAccel Method', $profile->hwaccelMethod ?: '(none)'],
                ['HWAccel Device', $profile->hwaccelDevice ?: '(auto)'],
                ['HWAccel Output Format', $profile->hwaccelOutputFormat ?: '(none)'],
                ['Is Hardware', $profile->isHardware() ? 'Yes' : 'No (software)'],
                ['Bitrate Multiplier', sprintf('%.2f', $multiplier)],
            ],
        );

        // --- FFmpeg flags ---
        $io->section('FFmpeg Input Flags');
        $io->table(
            ['Flag Type', 'Value'],
            [
                ['HWAccel Flags', $profile->hwaccelInputFlags() ?: '(none)'],
                ['Decoder Flags', $profile->decoderFlags() ?: '(none — resolved per-source at encode time)'],
            ],
        );

        // --- Per-source decoder resolution ---
        if ($profile->isHardware()) {
            $io->section('Decoder Resolution Per Source Codec');
            $decoderRows = [];
            foreach (['h264', 'hevc', 'av1', 'mpeg2video', 'vp9'] as $codec) {
                $resolved = $profile->withDecoderForSource($codec);
                $decoderRows[] = [$codec, $resolved->decoder ?: '(passthrough)'];
            }
            $io->table(['Source Codec', 'Decoder'], $decoderRows);
        }

        // --- Sample FFmpeg commands ---
        $io->section('Sample FFmpeg Commands');
        $tiers = [QualityTier::p720(), QualityTier::p1080(), QualityTier::p4K()];

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

            $io->text(sprintf('<info>%s</info> (init segment, h264 source):', $tier->name));
            $io->text($cmd);
            $io->newLine();
        }

        // --- HardwareAccelerator reference table ---
        $io->section('Hardware Accelerator Reference');
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
        $io->table(
            ['Accelerator', 'HEVC Encoder', 'H264 Encoder', 'HWAccel Method', 'HW Tonemap', 'Needs Device'],
            $refRows,
        );

        return Command::SUCCESS;
    }
}
