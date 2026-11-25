<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Shared\Domain\ValueObject\MediaContainer;
use App\Shared\Domain\ValueObject\MediaKind;
use App\Transcode\Application\Exception\UnsupportedMediaContainerException;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Application\DTO\AudioProbeResult;
use App\Transcode\Application\Port\AudioProbePortInterface;
use App\Transcode\Application\Port\VideoProbePortInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class FFprobeAdapter implements AudioProbePortInterface, VideoProbePortInterface
{
    /** The end of ffprobe's error text a failure reports. */
    private const int ERROR_REPORT_BYTES = 2000;

    public function __construct(
        private readonly JsonEncoder $jsonEncoder,
        private readonly string $ffprobePath = '/usr/bin/ffprobe',
    ) {
    }

    public function probeVideo(string $sourcePath): VideoProbeResult
    {
        return VideoProbeResult::fromProbeOutput($this->probe(
            $sourcePath,
            MediaKind::Video,
            FFprobeArguments::video($sourcePath, $this->ffprobePath),
        ));
    }

    public function probeAudio(string $sourcePath): AudioProbeResult
    {
        return AudioProbeResult::fromProbeOutput($this->probe(
            $sourcePath,
            MediaKind::Audio,
            FFprobeArguments::audio($sourcePath, $this->ffprobePath),
        ));
    }

    /**
     * FFmpeg refuses a demuxer outside the input's whitelist before ffprobe prints anything, and
     * names the refused demuxer in its error line. A probe that succeeds is checked against the
     * container table as well, so the whitelist is not the only barrier.
     *
     * @param list<string> $arguments
     * @return array<string, mixed>
     */
    private function probe(string $sourcePath, MediaKind $kind, array $arguments): array
    {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException(sprintf('Source file does not exist: %s', $sourcePath));
        }

        $result = ProcessExecutor::exec(
            $arguments,
            60,
        );

        if ($result['code'] !== 0) {
            if (preg_match('/\[([\w,]+) @ 0x[0-9a-f]+\] Format not on whitelist/', $result['error'], $refused) === 1) {
                throw new UnsupportedMediaContainerException($refused[1]);
            }
            throw new RuntimeException(sprintf(
                'ffprobe failed with code %d: %s',
                $result['code'],
                trim(substr($result['output'] . $result['error'], -self::ERROR_REPORT_BYTES)),
            ));
        }

        $probe = $this->jsonEncoder->decode($result['output'], 'json');
        $format = $probe['format']['format_name'] ?? null;
        if (!is_string($format) || MediaContainer::identify($kind, $format, pathinfo($sourcePath, PATHINFO_EXTENSION)) === null) {
            throw new UnsupportedMediaContainerException(is_string($format) ? $format : null);
        }

        return $probe;
    }
}
