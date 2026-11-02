<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Application\DTO\AudioProbeResult;
use App\Transcode\Application\Port\AudioProbePortInterface;
use App\Transcode\Application\Port\VideoProbePortInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class FFprobeAdapter implements AudioProbePortInterface, VideoProbePortInterface
{
    public function __construct(
        private readonly JsonEncoder $jsonEncoder,
        private readonly string $ffprobePath = '/usr/bin/ffprobe',
    ) {
    }

    public function probeVideo(string $sourcePath): VideoProbeResult
    {
        return VideoProbeResult::fromProbeOutput($this->probe($sourcePath, FFprobeArguments::video($sourcePath, $this->ffprobePath)));
    }

    public function probeAudio(string $sourcePath): AudioProbeResult
    {
        return AudioProbeResult::fromProbeOutput($this->probe($sourcePath, FFprobeArguments::audio($sourcePath, $this->ffprobePath)));
    }

    /** @param list<string> $arguments
     * @return array<string, mixed>
     */
    private function probe(string $sourcePath, array $arguments): array
    {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException(sprintf('Source file does not exist: %s', $sourcePath));
        }

        $result = ProcessExecutor::exec(
            $arguments,
            60,
        );

        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf(
                'ffprobe failed with code %d: %s',
                $result['code'],
                trim($result['output'] . $result['error']),
            ));
        }

        return $this->jsonEncoder->decode($result['output'], 'json');
    }
}
