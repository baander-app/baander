<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Transcode\Infrastructure\FFmpeg\AacImplementationProbe;

/** A probe whose FFmpeg listings name exactly the given codecs. */
final class StubAacImplementationProbe extends AacImplementationProbe
{
    /**
     * @param list<string> $encoders
     * @param list<string> $decoders
     */
    public function __construct(private readonly array $encoders = ['libbac', 'libfdk_aac', 'aac'], private readonly array $decoders = ['libbac', 'libfdk_aac', 'aac'])
    {
        parent::__construct('/usr/bin/ffmpeg');
    }

    protected function exec(array $command): string
    {
        $names = in_array('-encoders', $command, true) ? $this->encoders : $this->decoders;
        $lines = ["Encoders:", " V..... = Video", " ------"];
        foreach ($names as $name) {
            $lines[] = sprintf(' A....D %-20s %s (codec aac)', $name, $name);
        }

        return implode("\n", $lines) . "\n";
    }
}
