<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Exception;

/**
 * Thrown when a spawned FFmpeg process exits without producing the expected
 * output (init segment / media segments).
 *
 * FFmpeg writes diagnostics to stderr; when the process dies early the
 * encoding loop must surface that stderr rather than treating the exit as a
 * normal completion — otherwise init/segment encoding silently produces
 * nothing and the job hangs in "in_progress" forever.
 */
final class FFmpegProcessFailedException extends \RuntimeException
{
    /**
     * @param string $stderr The captured FFmpeg stderr output
     * @param int $exitCode The process exit code (-1 if unknown / signalled)
     * @param list<string> $producedFiles Segment files found in the output dir
     */
    public function __construct(
        string $message,
        private readonly string $stderr = '',
        private readonly int $exitCode = -1,
        private readonly array $producedFiles = [],
    ) {
        parent::__construct($message);
    }

    public function getStderr(): string
    {
        return $this->stderr;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * @return list<string>
     */
    public function getProducedFiles(): array
    {
        return $this->producedFiles;
    }

    /**
     * Build an instance from a drained FFmpeg process.
     *
     * @param string $stderr
     * @param int $exitCode
     * @param list<string> $producedFiles
     */
    public static function fromProcess(string $stderr, int $exitCode, array $producedFiles = []): self
    {
        $stderr = trim($stderr);
        $firstLine = $stderr !== '' ? explode("\n", $stderr)[0] : 'no stderr output';

        return new self(
            sprintf('FFmpeg exited with code %d without producing segments: %s', $exitCode, $firstLine),
            $stderr,
            $exitCode,
            $producedFiles,
        );
    }
}
