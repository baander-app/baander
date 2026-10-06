<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Console;

use App\Transcode\Application\Port\HardwareTranscodeDiagnosticsInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Debug command for verifying hardware transcoding configuration.
 *
 * Displays the diagnostic report with hwaccel flags, decoder flags, and sample FFmpeg
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
        private readonly HardwareTranscodeDiagnosticsInterface $diagnostics,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $report = $this->diagnostics->inspect();

        // --- Profile summary ---
        $io->section('Resolved Encoder Profile');
        $io->table(
            ['Property', 'Value'],
            [
                ['Accelerator', $report->accelerator],
                ['Encoder', $report->encoder],
                ['Decoder', $report->decoder ?: '(per-source)'],
                ['HWAccel Method', $report->hwaccelMethod ?: '(none)'],
                ['HWAccel Device', $report->hwaccelDevice ?: '(auto)'],
                ['HWAccel Output Format', $report->hwaccelOutputFormat ?: '(none)'],
                ['Is Hardware', $report->isHardware ? 'Yes' : 'No (software)'],
                ['Bitrate Multiplier', sprintf('%.2f', $report->bitrateMultiplier)],
            ],
        );

        // --- FFmpeg flags ---
        $io->section('FFmpeg Input Flags');
        $io->table(
            ['Flag Type', 'Value'],
            [
                ['HWAccel Flags', $report->hwaccelFlags ?: '(none)'],
                ['Decoder Flags', $report->decoderFlags ?: '(none — resolved per-source at encode time)'],
            ],
        );

        // --- Per-source decoder resolution ---
        if ($report->isHardware) {
            $io->section('Decoder Resolution Per Source Codec');
            $decoderRows = [];
            foreach ($report->sourceDecoders as $codec => $decoder) {
                $decoderRows[] = [$codec, $decoder ?: '(passthrough)'];
            }
            $io->table(['Source Codec', 'Decoder'], $decoderRows);
        }

        // --- Sample FFmpeg commands ---
        $io->section('Sample FFmpeg Commands');
        foreach ($report->sampleCommands as $tier => $cmd) {
            $io->text(sprintf('<info>%s</info> (init segment, h264 source):', $tier));
            $io->text($cmd);
            $io->newLine();
        }

        // --- HardwareAccelerator reference table ---
        $io->section('Hardware Accelerator Reference');
        $io->table(
            ['Accelerator', 'HEVC Encoder', 'H264 Encoder', 'HWAccel Method', 'HW Tonemap', 'Needs Device'],
            $report->acceleratorReference,
        );

        return Command::SUCCESS;
    }
}
