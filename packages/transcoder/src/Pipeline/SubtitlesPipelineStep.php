<?php

namespace Baander\Transcoder\Pipeline;

use Amp\Process\Process;
use Psr\Log\LoggerInterface;

class SubtitlesPipelineStep implements PipelineStepInterface
{
    public function __construct(
        private readonly FFmpegCommand   $ffmpegCommand,
        private readonly LoggerInterface $logger,
    )
    {
    }

    public function process(PipelineContext $context, ?callable $next): mixed
    {
        $this->logger->info('Processing subtitles.');

        $subtitles = $context['subtitles'] ?? [];
        $subtitleMode = $context->subtitleMode ?? 'external';

        if (empty($subtitles)) {
            $this->logger->info('No subtitles provided. Skipping subtitles processing.');
            return $next ? $next($context) : $context;
        }

        $context = match ($subtitleMode) {
            'burn-in' => $this->burnInSubtitles($context),
            'external' => $this->addExternalSubtitles($context),
            'hls' => $this->addHLSSubtitles($context),
            default => throw new \InvalidArgumentException("Unknown subtitle mode: $subtitleMode"),
        };

        return $next ? $next($context) : $context;
    }

    private function burnInSubtitles(PipelineContext $context): array
    {
        $this->logger->info('Burning subtitles into the video.');

        $inputFile = $context['mediaFilePath'];
        $outputFile = $context['outputDirectory'] . $context->basename();
        $subtitleFile = $context['subtitles'][0]; // Burn-in supports only one subtitle file at a time

        if (!file_exists($subtitleFile)) {
            throw new \RuntimeException("Subtitle file not found: $subtitleFile");
        }

        $command = $this->ffmpegCommand;
        $command->addArgument('-i', $inputFile)
            ->addArgument('-vf', "subtitles={$subtitleFile}")
            ->addArgument($outputFile, '');

        $this->logger->info("Executing FFmpeg command for burn-in: {$command->getCommand()}");

        // Process
        $process = Process::start($command->getCommand());
        $exitCode = $process->join();

        if ($exitCode !== 0) {
            $this->logger->error('Failed to burn-in subtitles. FFmpeg exited with code: ' . $exitCode);
            throw new \RuntimeException('Subtitle burn-in failed.');
        }

        $context['mediaFilePath'] = $outputFile;
        $this->logger->info("Subtitles burned into video: $outputFile");

        return $context;
    }

    private function addExternalSubtitles(array $context): array
    {
        $this->logger->info('Adding external subtitles.');

        $outputDir = $context['outputDirectory'];

        foreach ($context['subtitles'] as $subtitle) {
            if (!file_exists($subtitle)) {
                throw new \RuntimeException("Subtitle file not found: $subtitle");
            }

            $outputSubtitle = $outputDir . '/' . basename($subtitle);
            copy($subtitle, $outputSubtitle);

            $this->logger->info("External subtitle processed: $outputSubtitle");
        }

        return $context;
    }

    private function addHLSSubtitles(PipelineContext $context)
    {
        $this->logger->info('Packaging subtitles into HLS format.');

        $inputFile = $context->mediaFilePath;
        $outputDir = $context['outputDirectory'];

        $command = $this->ffmpegCommand;
        $command->addArgument('-i', $inputFile);

        // Add each subtitle as a separate stream
        foreach ($context['subtitles'] as $index => $subtitle) {
            if (!file_exists($subtitle)) {
                throw new \RuntimeException("Subtitle file not found: $subtitle");
            }

            $command->addArgument('-i', $subtitle)
                ->addArgument('-map', (string)($index + 1)) // Map subtitle stream
                ->addArgument('-c:s', 'webvtt'); // Convert to WebVTT
        }

        $command->addArgument('-f', 'hls'); // HLS formatting
        $command->addArgument($outputDir .'/' . basename($context['masterPlaylist-subs']) . '.m3u8', '');

        $this->logger->info("Executing FFmpeg command for HLS subtitles: {$command->getCommand()}");

        // Process
        $process = Process::start($command->getCommand());
        $exitCode = $process->join();

        if ($exitCode !== 0) {
            $this->logger->error('Failed to process HLS subtitles. FFmpeg exited with code: ' . $exitCode);
            throw new \RuntimeException('HLS subtitle packaging failed.');
        }

        $context['hlsPlaylist'] = $outputDir . '/hls-master-playlist.m3u8';
        $this->logger->info('HLS subtitles successfully added.');

        return $context;
    }
}