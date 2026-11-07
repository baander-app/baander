<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\AacImplementation;
use RuntimeException;

/**
 * Lists the AAC implementations the FFmpeg binary offers, as encoder and as
 * decoder, from one `-encoders` and one `-decoders` run per process. A job
 * that would use a missing implementation is refused before the encoder is
 * spawned, so a mismatch between the setting and the binary fails with a
 * reason that names the implementation instead of FFmpeg's own error.
 */
class AacImplementationProbe
{
    /** @var array<string, array{encoder: bool, decoder: bool}>|null */
    private ?array $codecs = null;

    public function __construct(private readonly string $ffmpegPath)
    {
    }

    /** @return list<AacImplementation> implementations present as encoder and decoder */
    public function available(): array
    {
        return array_values(array_filter(AacImplementation::cases(), $this->isAvailable(...)));
    }

    public function isAvailable(AacImplementation $implementation): bool
    {
        return $this->offersEncoder($implementation) && $this->offersDecoder($implementation);
    }

    public function offersEncoder(AacImplementation $implementation): bool
    {
        return $this->codecs()[$implementation->value]['encoder'];
    }

    public function offersDecoder(AacImplementation $implementation): bool
    {
        return $this->codecs()[$implementation->value]['decoder'];
    }

    public function assertAvailable(AacImplementation $implementation): void
    {
        if (!$this->isAvailable($implementation)) {
            throw new AacImplementationUnavailableException($implementation);
        }
    }

    /** @return array<string, array{encoder: bool, decoder: bool}> */
    private function codecs(): array
    {
        if ($this->codecs !== null) {
            return $this->codecs;
        }
        $encoders = self::names($this->exec([$this->ffmpegPath, '-hide_banner', '-encoders']));
        $decoders = self::names($this->exec([$this->ffmpegPath, '-hide_banner', '-decoders']));
        $this->codecs = [];
        foreach (AacImplementation::cases() as $implementation) {
            $this->codecs[$implementation->value] = [
                'encoder' => in_array($implementation->encoderName(), $encoders, true),
                'decoder' => in_array($implementation->decoderName(), $decoders, true),
            ];
        }

        return $this->codecs;
    }

    /** @return list<string> */
    private static function names(string $listing): array
    {
        $names = [];
        foreach (explode("\n", $listing) as $line) {
            if (preg_match('/^\s*[A-Z.]{6}\s+(\S+)\s/', $line, $matches) === 1) {
                $names[] = $matches[1];
            }
        }

        return $names;
    }

    /** @param list<string> $command */
    protected function exec(array $command): string
    {
        if (ProcessExecutor::usesNativeExecution()) {
            $result = ProcessExecutor::exec($command, 30);

            return $result['output'];
        }
        $output = tmpfile();
        if ($output === false) {
            throw new RuntimeException('Cannot create the codec listing output stream');
        }
        try {
            $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => ['file', '/dev/null', 'w']], $pipes);
            if ($process === false) {
                throw new RuntimeException(sprintf('Failed to execute: %s', $command[0]));
            }
            proc_close($process);
            rewind($output);
            $listing = stream_get_contents($output);

            return $listing === false ? '' : $listing;
        } finally {
            fclose($output);
        }
    }
}
