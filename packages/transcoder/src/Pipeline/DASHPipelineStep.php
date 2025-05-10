<?php

namespace Baander\Transcoder\Pipeline;

use Baander\Transcoder\Transcoder\TranscodeBuilder;
use Psr\Log\LoggerInterface;

class DASHPipelineStep implements PipelineStepInterface
{
    public function __construct(
        private readonly TranscodeBuilder $builder,
        private readonly LoggerInterface $logger,
        private readonly ProfileManager $profileManager
    ) {}

    public function process(mixed $context, ?callable $next): mixed
    {
        $this->logger->info('Starting DASH transcoding.');

        $profiles = $this->profileManager->getDASHProfiles();
        $outputDir = $context['outputDirectory'] ?? '/output';

        try {
            $process = $this->builder->createDASHSegmentsAndManifest($profiles, $outputDir);
            $context['dashManifest'] = "$outputDir/manifest.mpd";

            $process->run();

            if (!file_exists($context['dashManifest'])) {
                throw new \RuntimeException('Missing DASH manifest file.');
            }

            $this->logger->info('DASH manifest successfully created at: ' . $context['dashManifest']);
        } catch (\Throwable $e) {
            $this->logger->error('DASH transcoding failed: ' . $e->getMessage());
            throw $e;
        }

        return $next ? $next($context) : $context;
    }
}
