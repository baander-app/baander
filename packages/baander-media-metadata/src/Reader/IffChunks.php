<?php

declare(strict_types=1);

namespace Baander\MediaMetadata\Reader;

/** RIFF and FORM chunks, with audio payloads skipped by seeking. */
final class IffChunks
{
    /**
     * @param list<string> $forms
     * @param array<string, int> $payloadLimits
     * @return \Generator<int, array{id: string, size: int, data: string, form: string}>
     */
    public static function read(string $path, string $signature, array $forms, array $payloadLimits): \Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open audio file');
        }
        try {
            $header = fread($handle, 12);
            if (strlen($header) !== 12 || substr($header, 0, 4) !== $signature || !in_array(substr($header, 8, 4), $forms, true)) {
                return;
            }
            $order = $signature === 'RIFF' ? 'V' : 'N';
            $declared = unpack($order, substr($header, 4, 4))[1] + 8;
            $end = min($declared, fstat($handle)['size']);
            $offset = 12;
            $budget = 32 * 1024 * 1024;
            for ($count = 0; $count < 100000 && $offset + 8 <= $end; ++$count) {
                fseek($handle, $offset);
                $chunk = fread($handle, 8);
                if (strlen($chunk) !== 8) {
                    break;
                }
                $id = substr($chunk, 0, 4);
                $size = unpack($order, substr($chunk, 4, 4))[1];
                $offset += 8;
                if ($size > $end - $offset) {
                    break;
                }
                $data = '';
                if (isset($payloadLimits[$id]) && $size <= $payloadLimits[$id] && $size <= $budget) {
                    $data = $size > 0 ? fread($handle, $size) : '';
                    $budget -= $size;
                }
                yield ['id' => $id, 'size' => $size, 'data' => $data, 'form' => substr($header, 8, 4)];
                $offset += $size + ($size % 2);
            }
        } finally {
            fclose($handle);
        }
    }
}
