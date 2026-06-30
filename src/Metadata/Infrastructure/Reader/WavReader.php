<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Reader;

use App\Metadata\Domain\Model\ExtractedMetadata;
use Psr\Log\LoggerInterface;

/**
 * Reads WAV/RIFF metadata by parsing the binary file directly.
 *
 * Extracts:
 *   - fmt  chunk: sample rate, channels, bits per sample, byte rate (bitrate)
 *   - LIST/INFO chunks: title (INAM), artist (IART), album (IPRD), track (ITRK),
 *     genre (IGNR), year (ICRD), comment (ICMT), composer (IMUS), bpm (IBPM)
 *   - data chunk: size + fmt sample rate → duration
 */
final class WavReader
{
    private const string RIFF_SIGNATURE = 'RIFF';
    private const string WAVE_FORMAT = 'WAVE';
    private const string FMT_CHUNK = 'fmt ';
    private const string DATA_CHUNK = 'data';
    private const string LIST_CHUNK = 'LIST';
    private const string INFO_TYPE = 'INFO';

    /** @see https://www.sno.phy.queensu.ca/~phil/exiftool/TagNames/RIFF.html#Info */
    private const array INFO_TAGS = [
        'INAM' => 'title',
        'IART' => 'artist',
        'IPRD' => 'album',
        'ITRK' => 'tracknumber',
        'IGNR' => 'genre',
        'ICRD' => 'year',
        'ICMT' => 'comment',
        'IMUS' => 'composer',
        'IBPM' => 'bpm',
        'ILNG' => 'language',
        'ISFT' => 'software',
        'ISRF' => 'originalartist',
        'ITCH' => 'technician',
        'IENG' => 'engineer',
        'ISMP' => 'samplerate',
    ];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function read(string $path): ExtractedMetadata
    {
        if (!is_file($path) || !is_readable($path)) {
            $this->logger->warning('WAV file does not exist or is not readable', ['path' => $path]);

            return new ExtractedMetadata();
        }

        try {
            $data = $this->parseFile($path);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to parse WAV file', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return new ExtractedMetadata();
        }

        return $this->mapToMetadata($data);
    }

    /**
     * @return array{fmt: ?array{sampleRate: int, channels: int, bitsPerSample: int, byteRate: int, audioFormat: int}, dataSize: ?int, info: array<string, string>}
     */
    private function parseFile(string $path): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open WAV file: {$path}");
        }

        try {
            $this->validateSignature($handle);
        } finally {
            fclose($handle);
        }

        // Read the entire file into memory for chunk scanning.
        // WAV files are typically not huge and this avoids seek complexity.
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException("Cannot read WAV file: {$path}");
        }

        $fmt = null;
        $dataSize = null;
        $info = [];

        // Skip RIFF header (12 bytes): "RIFF" + 4-byte size + "WAVE"
        $offset = 12;
        $fileSize = strlen($contents);

        while ($offset + 8 <= $fileSize) {
            $chunkId = substr($contents, $offset, 4);
            $chunkSize = unpack('V', substr($contents, $offset + 4, 4))[1];
            $offset += 8;

            if ($chunkSize > $fileSize - $offset) {
                break; // Truncated or corrupt
            }

            $chunkData = substr($contents, $offset, $chunkSize);

            match ($chunkId) {
                self::FMT_CHUNK => $fmt = $this->parseFmtChunk($chunkData),
                self::DATA_CHUNK => $dataSize = $chunkSize,
                self::LIST_CHUNK => $info = [...$info, ...$this->parseListChunk($chunkData)],
                default => null,
            };

            // Chunks are word-aligned (pad to even size)
            $offset += $chunkSize + ($chunkSize % 2);
        }

        $this->logger->debug('WavReader: parsed WAV file', [
            'path' => $path,
            'has_fmt' => $fmt !== null,
            'has_data' => $dataSize !== null,
            'data_size' => $dataSize,
            'info_fields' => array_keys($info),
        ]);

        return ['fmt' => $fmt, 'dataSize' => $dataSize, 'info' => $info];
    }

    /**
     * @return array{sampleRate: int, channels: int, bitsPerSample: int, byteRate: int, audioFormat: int}
     */
    private function parseFmtChunk(string $data): array
    {
        if (strlen($data) < 16) {
            return [
                'sampleRate' => 0,
                'channels' => 0,
                'bitsPerSample' => 0,
                'byteRate' => 0,
                'audioFormat' => 0,
            ];
        }

        $audioFormat = unpack('v', substr($data, 0, 2))[1];
        $channels = unpack('v', substr($data, 2, 2))[1];
        $sampleRate = unpack('V', substr($data, 4, 4))[1];
        $byteRate = unpack('V', substr($data, 8, 4))[1];
        $bitsPerSample = strlen($data) >= 18 ? unpack('v', substr($data, 14, 2))[1] : 0;

        return [
            'audioFormat' => $audioFormat,
            'sampleRate' => $sampleRate,
            'channels' => $channels,
            'bitsPerSample' => $bitsPerSample,
            'byteRate' => $byteRate,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseListChunk(string $data): array
    {
        if (strlen($data) < 4) {
            return [];
        }

        $listType = substr($data, 0, 4);

        if ($listType !== self::INFO_TYPE) {
            return [];
        }

        $info = [];
        $offset = 4;

        while ($offset + 8 <= strlen($data)) {
            $tagId = substr($data, $offset, 4);
            $tagSize = unpack('V', substr($data, $offset + 4, 4))[1];
            $offset += 8;

            if ($tagSize > strlen($data) - $offset) {
                break;
            }

            $value = substr($data, $offset, $tagSize);
            // Strip trailing null bytes from INFO strings
            $value = rtrim($value, "\0");

            if ($value !== '') {
                $info[$tagId] = $value;
            }

            $offset += $tagSize + ($tagSize % 2);
        }

        return $info;
    }

    private function mapToMetadata(array $data): ExtractedMetadata
    {
        $metadata = new ExtractedMetadata();
        $fmt = $data['fmt'];
        $info = $data['info'];

        // Audio properties from fmt chunk
        if ($fmt !== null && $fmt['sampleRate'] > 0) {
            $metadata->setSampleRate($fmt['sampleRate']);
            $metadata->setChannels($fmt['channels']);
            $metadata->setBitrate($fmt['byteRate'] * 8); // bytes/sec → bits/sec

            // Duration from data chunk size
            if ($data['dataSize'] !== null && $data['dataSize'] > 0) {
                $bytesPerSample = ($fmt['bitsPerSample'] / 8) * $fmt['channels'];
                if ($bytesPerSample > 0) {
                    $metadata->setDuration(
                        (float) ($data['dataSize'] / ($fmt['sampleRate'] * $bytesPerSample)),
                    );
                }
            }
        }

        // INFO tags
        $fieldMap = [
            'title' => fn (string $v) => $metadata->setTitle($v),
            'artist' => fn (string $v) => $metadata->setArtist($v),
            'album' => fn (string $v) => $metadata->setAlbum($v),
            'comment' => fn (string $v) => $metadata->setComment($v),
            'composer' => fn (string $v) => $metadata->setComposer($v),
        ];

        foreach (self::INFO_TAGS as $tagId => $field) {
            $value = $info[$tagId] ?? null;
            if ($value === null) {
                continue;
            }

            if (isset($fieldMap[$field])) {
                $fieldMap[$field]($value);
                continue;
            }

            if ($field === 'genre') {
                $metadata->setGenre(array_map('trim', explode(',', $value)));
            } elseif ($field === 'year') {
                $parsed = (int) substr($value, 0, 4);
                if ($parsed > 0) {
                    $metadata->setYear($parsed);
                }
            } elseif ($field === 'tracknumber') {
                $parsed = (int) preg_replace('/[^0-9].*/', '', $value);
                if ($parsed > 0) {
                    $metadata->setTrackNumber($parsed);
                }
            } elseif ($field === 'bpm') {
                $parsed = (int) $value;
                if ($parsed > 0) {
                    $metadata->setBpm($parsed);
                }
            }
        }

        return $metadata;
    }

    private function validateSignature($handle): void
    {
        $signature = fread($handle, 12);

        if ($signature === false || strlen($signature) < 12) {
            throw new \RuntimeException('Invalid WAV file: cannot read RIFF header.');
        }

        $riff = substr($signature, 0, 4);
        $format = substr($signature, 8, 4);

        if ($riff !== self::RIFF_SIGNATURE) {
            throw new \RuntimeException('Invalid WAV file: missing RIFF signature.');
        }

        if ($format !== self::WAVE_FORMAT) {
            throw new \RuntimeException('Invalid WAV file: not WAVE format.');
        }
    }
}
