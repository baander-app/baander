<?php

namespace Baander\Transcoder\Probe;

use Amp\ByteStream\ReadableResourceStream;
use Amp\Cancellation;
use Amp\Future;
use Amp\Process\Process;
use Amp\Redis\RedisClient;
use Baander\Transcoder\Probe\Models\FFprobeMapper;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class Probe
{

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Logger for handling logs (errors, output).
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly RedisClient $redisClient,
    )
    {
    }

    /**
     * Runs FFprobe and retrieves metadata as a parsed object using Amp processes.
     *
     * @param string $filePath The video/audio file path to be analyzed.
     * @param array<string, string> $options FFprobe configuration options (key-value pairs).
     * @param Cancellation|null $cancellation Optional cancellation token for the operation.
     * @return Future<\Baander\Transcoder\Probe\Models\FFprobeMetadata> Resolves with the mapped FFprobe metadata.
     */
    public function analyze(string $filePath, array $options = [], ?Cancellation $cancellation = null): Future
    {
        return \Amp\async(function () use ($filePath, $options, $cancellation) {
            $cacheddata = $this->redisClient->get('ffprobe:' . md5($filePath));
            if ($cacheddata) {
                return json_decode($cacheddata, true);
            }

            // Prepare FFprobe options
            $ffprobeArguments = $this->buildOptionsString($options);

            // Complete FFprobe command
            $command = sprintf(
                'ffprobe -v error %s -of json "%s"',
                $ffprobeArguments,
                $filePath
            );

            $this->logger->info("Executing FFprobe: $command");

            // Start the process
            $process = Process::start($command, null, [], [], $cancellation);
            $stdoutStream = $process->getStdout();
            $stderrStream = $process->getStderr();

            // Collect process output
            $output = '';
            $error = '';

            yield [
                $this->processStream($stdoutStream, $output),
                $this->processStream($stderrStream, $error),
            ];

            $exitCode = yield $process->join();

            if ($exitCode !== 0) {
                $this->logger->error("FFprobe failed for file $filePath with error: $error");
                throw new \RuntimeException("FFprobe failed with exit code $exitCode: $error");
            }

            $this->logger->info("FFprobe completed successfully for file $filePath");

            $metadata = json_decode($output, true);

            if (json_last_error() !== JSON_ERROR_NONE || empty($metadata)) {
                throw new \RuntimeException(
                    'Failed to parse FFprobe output. Error: ' . json_last_error_msg()
                );
            }

            return FFprobeMapper::map($metadata);
        });
    }

    /**
     * Processes the given stream (stdout/stderr) into a string.
     *
     * @param ReadableResourceStream $stream Readable stream for capturing FFprobe output.
     * @param string $output Reference variable to store the stream output.
     * @return \Amp\Future<void>
     */
    private function processStream(ReadableResourceStream $stream, string &$output): \Amp\Future
    {
        return \Amp\async(function () use ($stream, &$output) {
            while (($chunk = yield $stream->read()) !== null) {
                $output .= $chunk;
            }
        });
    }

    /**
     * Builds a command-line string for FFprobe options.
     *
     * @param array<string, string> $options Key => Value pairs for FFprobe options.
     * @return string
     */
    private function buildOptionsString(array $options): string
    {
        $args = [];
        foreach ($options as $key => $value) {
            $args[] = sprintf('-%s %s', $key, escapeshellarg($value));
        }

        return implode(' ', $args);
    }

}
