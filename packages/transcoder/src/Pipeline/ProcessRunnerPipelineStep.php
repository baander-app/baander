<?php

namespace Baander\Transcoder\Pipeline;

use Amp\Cancellation;
use Amp\Process\Process;
use Psr\Log\LoggerInterface;

class ProcessRunnerPipelineStep implements PipelineStepInterface
{
    public function __construct(
        private readonly FFmpegCommand $command,
        private readonly Cancellation $cancellation,
        private readonly LoggerInterface $logger
    ) {}

    public function process(PipelineContext $context, ?callable $next): mixed
    {
        if ($context['type'] === 'DirectPlay') {
            $this->logger->info('Direct play, skipping transcoding.');

            if (empty($context['mediaFilePath']) || !file_exists($context['mediaFilePath'])) {
                throw new \RuntimeException('DirectPlay file not found.');
            }

            $context['files'][] = $context['mediaFilePath'];

            return $next ? $next($context) : $context;
        }

        $this->logger->info('Executing FFmpeg: ' . $this->command->getCommand());
        $process = Process::start($this->command->getCommand(), null, [], [], $this->cancellation);

        $exitCode = $process->join();

        if ($exitCode !== 0) {
            $this->logger->error('FFmpeg failed with exit code: ' . $exitCode);
            throw new \RuntimeException('FFmpeg failed.');
        }

        $this->logger->info('FFmpeg finished successfully.');

        return $next ? $next($context) : $context;
    }
}
