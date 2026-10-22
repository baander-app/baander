<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\HLS;

/** Clips source cues to the local timeline of a restarted video generation. */
final class WebVttTimeline
{
    private const int MAX_FILE_BYTES = 16 * 1024 * 1024;
    private const int MAX_BLOCK_BYTES = 1024 * 1024;
    private const int MAX_LINE_BYTES = 64 * 1024;
    private const string TIMING = '/^((?:[0-9]{2,}:)?[0-9]{2}:[0-9]{2}\.[0-9]{3})[\t ]+-->[\t ]+((?:[0-9]{2,}:)?[0-9]{2}:[0-9]{2}\.[0-9]{3})([\t ].*)?$/D';

    public function rebase(string $source, string $destination, float $startTime): void
    {
        if (!is_finite($startTime) || $startTime < 0) {
            throw new \InvalidArgumentException('Subtitle timeline start must be finite and nonnegative.');
        }
        clearstatcache(true, $source);
        $size = filesize($source);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('Subtitle output exceeds the 16 MiB processing limit.');
        }
        $input = fopen($source, 'rb');
        if ($input === false) {
            throw new \RuntimeException('Subtitle output could not be opened.');
        }
        $output = @fopen($destination, 'wb');
        if ($output === false) {
            fclose($input);
            throw new \RuntimeException('Subtitle timeline output could not be opened.');
        }
        try {
            $start = (int) round($startTime * 1000);
            $block = '';
            $bytes = 0;
            while (($line = fgets($input, self::MAX_LINE_BYTES + 2)) !== false) {
                $bytes += strlen($line);
                if (strlen($line) > self::MAX_LINE_BYTES || $bytes > self::MAX_FILE_BYTES) {
                    throw new \RuntimeException('Subtitle output exceeds its processing limits.');
                }
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    $this->writeBlock($output, $block, $start);
                    $block = '';
                    continue;
                }
                $block .= ($block === '' ? '' : "\n") . $line;
                if (strlen($block) > self::MAX_BLOCK_BYTES) {
                    throw new \RuntimeException('Subtitle cue exceeds the 1 MiB processing limit.');
                }
            }
            if (!feof($input)) {
                throw new \RuntimeException('Subtitle output could not be read completely.');
            }
            $this->writeBlock($output, $block, $start);
        } catch (\Throwable $error) {
            fclose($output);
            @unlink($destination);
            throw $error;
        } finally {
            fclose($input);
        }
        fclose($output);
    }

    /** @param resource $output */
    private function writeBlock($output, string $block, int $start): void
    {
        if ($block === '') {
            return;
        }
        $lines = explode("\n", $block);
        foreach (array_slice($lines, 0, 2, true) as $index => $line) {
            if (preg_match(self::TIMING, $line, $match) !== 1) {
                continue;
            }
            $end = $this->milliseconds($match[2]);
            if ($end <= $start) {
                return;
            }
            $begin = max(0, $this->milliseconds($match[1]) - $start);
            $lines[$index] = $this->timestamp($begin) . ' --> ' . $this->timestamp($end - $start) . ($match[3] ?? '');
            break;
        }
        $data = implode("\n", $lines) . "\n\n";
        if (fwrite($output, $data) !== strlen($data)) {
            throw new \RuntimeException('Subtitle timeline output could not be written completely.');
        }
    }

    private function milliseconds(string $timestamp): int
    {
        $parts = explode(':', $timestamp);
        $seconds = (float) array_pop($parts);
        $minutes = (int) array_pop($parts);
        $hours = $parts === [] ? 0 : (int) $parts[0];
        return (int) round(($hours * 3600 + $minutes * 60 + $seconds) * 1000);
    }

    private function timestamp(int $milliseconds): string
    {
        return sprintf('%02d:%02d:%02d.%03d', intdiv($milliseconds, 3_600_000), intdiv($milliseconds, 60_000) % 60, intdiv($milliseconds, 1000) % 60, $milliseconds % 1000);
    }
}
