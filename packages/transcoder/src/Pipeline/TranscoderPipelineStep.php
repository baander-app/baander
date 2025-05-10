<?php

namespace Baander\Transcoder\Pipeline;

use Baander\Common\Streaming\ClientCapabilities;
use Baander\Transcoder\Matching\MediaMatcher;
use Baander\Transcoder\Transcoder\TranscodeBuilder;
use Baander\Common\Streaming\MediaMetadata;
use Psr\Log\LoggerInterface;

final class TranscoderPipelineStep implements PipelineStepInterface
{
    private ClientCapabilities $clientCapabilities;
    private MediaMetadata $mediaMetadata;
    private TranscodeBuilder $transcodeBuilder;
    private LoggerInterface $logger;

    public function __construct(
        ClientCapabilities $capabilities,
        MediaMetadata $metadata,
        TranscodeBuilder $builder,
        LoggerInterface $logger
    ) {
        $this->clientCapabilities = $capabilities;
        $this->mediaMetadata = $metadata;
        $this->transcodeBuilder = $builder;
        $this->logger = $logger;
    }

    /**
     * Process the transcoding or streaming step according to the capabilities.
     */
    public function process(mixed $context, ?callable $next): mixed
    {
        // Decide the type of process based on capabilities and metadata
        $decision = MediaMatcher::decideProcessType($this->clientCapabilities, $this->mediaMetadata);

        $this->logger->info("Transcoding decision: {$decision}");

        $context['files'][] = match ($decision) {
            'DirectPlay' => $this->mediaMetadata->filePath,
            'DirectStream' => $this->transcodeBuilder->buildRemuxProcess()->run(),
            'Transcode' => $this->transcodeBuilder->buildTranscodeProcess()->run(),
            default => throw new \RuntimeException('Unknown process type'),
        };

        return $next ? $next($context) : $context;
    }
}