<?php

namespace Baander\Transcoder\Pipeline;

use Baander\Transcoder\Playlist\PlaylistManager;
use Baander\Transcoder\Transcoder\TranscodeBuilder;
use Psr\Log\LoggerInterface;

class HLSPipelineStep implements PipelineStepInterface
{
    public function __construct(
        private readonly PlaylistManager $playlistManager,
        private readonly TranscodeBuilder $transcodeBuilder,
        private readonly LoggerInterface $logger,
        private readonly ProfileManager $profileManager
    ) {}

    public function process(PipelineContext $context, ?callable $next): mixed
    {
        $this->logger->info('Starting HLS transcoding.');

        $profiles = $this->profileManager->getHLSProfiles();
        $outputDir = $context->outputDirectory ?? '/output';

        foreach ($profiles as $profile) {
            $this->transcodeBuilder->buildABRHLSStream($profile, $outputDir);
        }

        $masterPlaylistPath = $this->playlistManager->createMasterPlaylist($profiles, $outputDir);

        if (!file_exists($masterPlaylistPath)) {
            throw new \RuntimeException('HLS master playlist is missing.');
        }

        $context->masterPlaylist = $masterPlaylistPath;

        $this->logger->info('HLS master playlist generated: ' . $masterPlaylistPath);

        return $next ? $next($context) : $context;
    }
}
