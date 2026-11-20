<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer;

/** Leaves incomplete final records at their byte position for the next collection pass. */
final readonly class FileRecordReader
{
    public function __construct(private int $maxReadBytes = 8388608, private int $maxRecordBytes = LogNormalizer::MAX_RECORD_BYTES) {}

    public static function startsRecord(string $line): bool
    {
        return preg_match('/^\S/', $line) === 1;
    }

    /**
     * @param array<string, mixed> $checkpoint
     * @return array{records:list<array{raw:string,offset:int}>,checkpoint:array<string,mixed>,issues:list<string>}
     */
    public function read(string $path, array $checkpoint = [], bool $final = false): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open admitted log file.');
        }
        try {
            $stat = fstat($stream);
            if ($stat === false) {
                throw new \RuntimeException('Cannot inspect log file.');
            }
            $issues = [];
            $offset = (int) ($checkpoint['offset'] ?? 0);
            $generation = (string) ($checkpoint['generation'] ?? bin2hex(random_bytes(16)));
            $identityChanged = isset($checkpoint['inode']) && ($checkpoint['inode'] !== $stat['ino'] || ($checkpoint['device'] ?? null) !== $stat['dev']);
            // A retained prefix detects copy-truncate followed by regrowth beyond the old offset.
            $prefix = $offset > 0 ? fread($stream, min($offset, 256)) : '';
            $prefixHash = hash('sha256', $prefix === false ? '' : $prefix);
            if ($identityChanged || $stat['size'] < $offset || ($offset > 0 && isset($checkpoint['prefixHash']) && $prefixHash !== $checkpoint['prefixHash'])) {
                $offset = 0;
                $generation = bin2hex(random_bytes(16));
                $issues[] = 'File generation changed; history before the observed boundary may be unavailable.';
            }
            fseek($stream, $offset);
            $records = [];
            $pending = '';
            $pendingStart = $offset;
            $read = 0;
            $lastCommitted = $offset;
            $truncated = false;
            $lastLineComplete = true;
            while (!feof($stream) && $read < $this->maxReadBytes) {
                $line = fgets($stream, min(8193, $this->maxReadBytes - $read + 1));
                if ($line === false) {
                    break;
                }
                $read += strlen($line);
                $position = ftell($stream);
                if ($pending !== '' && self::startsRecord($line) && $lastLineComplete) {
                    $records[] = ['raw' => $pending, 'offset' => $pendingStart];
                    $lastCommitted = $position - strlen($line);
                    $pendingStart = $lastCommitted;
                    $pending = '';
                    $truncated = false;
                }
                if (!$truncated) {
                    $remaining = $this->maxRecordBytes + 1 - strlen($pending);
                    $pending .= substr($line, 0, max(0, $remaining));
                    if (strlen($line) > $remaining) {
                        $truncated = true;
                        $issues[] = 'Record exceeded retained byte limit.';
                    }
                }
                $lastLineComplete = str_ends_with($line, "\n");
            }
            if (($final && feof($stream) || $truncated && $read >= $this->maxReadBytes) && $pending !== '') {
                $records[] = ['raw' => $pending, 'offset' => $pendingStart];
                $lastCommitted = ftell($stream);
            }
            fseek($stream, 0);
            $prefix = $lastCommitted > 0 ? fread($stream, min($lastCommitted, 256)) : '';
            return ['records' => $records, 'checkpoint' => ['offset' => $lastCommitted, 'device' => $stat['dev'], 'inode' => $stat['ino'], 'generation' => $generation, 'prefixHash' => hash('sha256', $prefix === false ? '' : $prefix)], 'issues' => $issues |> array_unique(...) |> array_values(...)];
        } finally {
            fclose($stream);
        }
    }
}
