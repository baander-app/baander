<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\VideoProbeResult;
use InvalidArgumentException;
use RuntimeException;
use Swoole\Coroutine\System;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class FFprobeAdapter
{
    public function __construct(
        private readonly JsonEncoder $jsonEncoder,
        private readonly string $ffprobePath = '/usr/local/bin/ffprobe',
    ) {
    }

    public function probeVideo(string $sourcePath): VideoProbeResult
    {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException(sprintf('Source file does not exist: %s', $sourcePath));
        }

        $result = System::exec(
            sprintf('%s -v quiet -print_format json -show_format -show_streams %s', $this->ffprobePath, escapeshellarg($sourcePath)),
            60,
        );

        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf(
                'ffprobe failed with code %d: %s',
                $result['code'],
                $result['output'] ?? '',
            ));
        }

        $raw = $this->jsonEncoder->decode($result['output'], 'json');

        return VideoProbeResult::fromProbeOutput($raw);
    }
}
