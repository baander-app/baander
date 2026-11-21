<?php

declare(strict_types=1);

namespace Baander\MediaMetadata\Parser;

use Baander\MediaMetadata\Model\CoverArt;

/** Reads bounded header objects; media packets are never loaded. */
final class AsfParser
{
    private const string HEADER = '3026b2758e66cf11a6d900aa0062ce6c';
    private const string CONTENT = '3326b2758e66cf11a6d900aa0062ce6c';
    private const string EXTENDED = '40a4d0d207e3d21197f000a0c95ea850';
    private const string EXTENSION = 'b503bf5f2ea9cf118ee300c00c205365';
    private const string METADATA = 'eacbf8c5af5b77488467aa8c44fa4cca';
    private const string LIBRARY = '941c23449894d149a1411d134e457054';
    private const string FILE = 'a1dcab8c47a9cf118ee400c00c205365';
    private const string STREAM = '9107dcb7b7a9cf118ee600c00c205365';
    private const int MAX_METADATA_BYTES = 33_554_432;

    /** @var array<string, list<string>> */
    private array $fields = [];
    /** @var list<CoverArt> */
    private array $pictures = [];
    /** @var array{duration?: float, bitrate?: int, sampleRate?: int, channels?: int} */
    private array $streamInfo = [];
    private int $bytesRead = 0;

    public function __construct(private readonly string $path) {}

    public function parse(): void
    {
        $this->fields = [];
        $this->pictures = [];
        $this->streamInfo = [];
        $this->bytesRead = 0;
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open ASF file');
        }
        try {
            $fileSize = fstat($handle)['size'];
            $header = $this->read($handle, 30);
            $size = self::uint64(substr($header, 16, 8));
            if (bin2hex(substr($header, 0, 16)) !== self::HEADER || $size < 30 || $size > $fileSize) {
                throw new \UnexpectedValueException('Invalid ASF header');
            }
            $count = self::uint32(substr($header, 24, 4));
            if ($count > intdiv($size - 30, 24)) {
                throw new \UnexpectedValueException('Invalid ASF object count');
            }
            $this->objects($handle, $size, $count);
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, list<string>> */
    public function getFields(): array
    {
        return $this->fields;
    }

    /** @return list<CoverArt> */
    public function getPictures(): array
    {
        return $this->pictures;
    }

    /** @return array{duration?: float, bitrate?: int, sampleRate?: int, channels?: int} */
    public function getStreamInfo(): array
    {
        return $this->streamInfo;
    }

    /** @param resource $handle */
    private function objects($handle, int $end, ?int $count = null, bool $nested = false): void
    {
        $seen = 0;
        while (ftell($handle) < $end && ($count === null || $seen < $count)) {
            $start = ftell($handle);
            if ($end - $start < 24) {
                throw new \UnexpectedValueException('Truncated ASF object');
            }
            $header = $this->read($handle, 24);
            $guid = bin2hex(substr($header, 0, 16));
            $size = self::uint64(substr($header, 16, 8));
            if ($size < 24 || $size > $end - $start) {
                throw new \UnexpectedValueException('Invalid ASF object size');
            }
            $objectEnd = $start + $size;
            if ($guid === self::EXTENSION && !$nested) {
                if ($size < 46) {
                    throw new \UnexpectedValueException('Truncated ASF header extension');
                }
                $extension = $this->read($handle, 22);
                $length = self::uint32(substr($extension, 18, 4));
                if ($length > $size - 46) {
                    throw new \UnexpectedValueException('Invalid ASF header extension length');
                }
                $this->objects($handle, ftell($handle) + $length, nested: true);
            } elseif (in_array($guid, [self::CONTENT, self::EXTENDED, self::METADATA, self::LIBRARY, self::FILE, self::STREAM], true)) {
                $this->parseObject($guid, $this->read($handle, $size - 24));
            }
            if (fseek($handle, $objectEnd) !== 0) {
                throw new \UnexpectedValueException('Cannot seek ASF object');
            }
            ++$seen;
        }
    }

    private function parseObject(string $guid, string $data): void
    {
        $offset = 0;
        if ($guid === self::CONTENT) {
            $lengths = unpack('v5', self::take($data, $offset, 10));
            foreach (['TITLE', 'AUTHOR', 'COPYRIGHT', 'DESCRIPTION', 'RATING'] as $index => $field) {
                $this->add($field, self::text(self::take($data, $offset, $lengths[$index + 1])));
            }
        } elseif (in_array($guid, [self::EXTENDED, self::METADATA, self::LIBRARY], true)) {
            $count = self::uint16(self::take($data, $offset, 2));
            for ($index = 0; $index < $count; ++$index) {
                if ($guid === self::EXTENDED) {
                    $nameLength = self::uint16(self::take($data, $offset, 2));
                    $name = self::text(self::take($data, $offset, $nameLength));
                    $type = self::uint16(self::take($data, $offset, 2));
                    $length = self::uint16(self::take($data, $offset, 2));
                } else {
                    self::take($data, $offset, 4); // Language/reserved and stream number.
                    $nameLength = self::uint16(self::take($data, $offset, 2));
                    $type = self::uint16(self::take($data, $offset, 2));
                    $length = self::uint32(self::take($data, $offset, 4));
                    $name = self::text(self::take($data, $offset, $nameLength));
                }
                $value = self::take($data, $offset, $length);
                if ($type === 1 && strtoupper($name) === 'WM/PICTURE') {
                    $this->picture($value);
                } elseif ($type === 0) {
                    $this->add($name, self::text($value));
                } elseif ($type === 3 && $length === 4) {
                    $this->add($name, (string) self::uint32($value));
                } elseif ($type === 5 && $length === 2) {
                    $this->add($name, (string) self::uint16($value));
                }
            }
        } elseif ($guid === self::FILE && strlen($data) >= 80) {
            $duration = self::uint64(substr($data, 40, 8)) / 10_000_000 - self::uint64(substr($data, 56, 8)) / 1000;
            if ($duration > 0 && (self::uint32(substr($data, 64, 4)) & 1) === 0) {
                $this->streamInfo['duration'] = $duration;
            }
        } elseif ($guid === self::STREAM && strlen($data) >= 70 && bin2hex(substr($data, 0, 16)) === '409e69f84d5bcf11a8fd00805f5c442b') {
            $length = self::uint32(substr($data, 40, 4));
            if ($length >= 16 && $length <= strlen($data) - 54) {
                $this->streamInfo['channels'] = self::uint16(substr($data, 56, 2));
                $this->streamInfo['sampleRate'] = self::uint32(substr($data, 58, 4));
                $this->streamInfo['bitrate'] = self::uint32(substr($data, 62, 4)) * 8;
            }
        }
    }

    private function picture(string $data): void
    {
        $offset = 0;
        $type = ord(self::take($data, $offset, 1));
        $length = self::uint32(self::take($data, $offset, 4));
        $mime = self::terminatedText($data, $offset);
        $description = self::terminatedText($data, $offset);
        $image = self::take($data, $offset, $length);
        if ($image !== '') {
            $this->pictures[] = new CoverArt($type, $mime, $description, $image);
        }
    }

    private function add(string $name, string $value): void
    {
        $value = trim($value);
        if ($value !== '') {
            $this->fields[strtoupper($name)][] = $value;
        }
    }

    private static function terminatedText(string $data, int &$offset): string
    {
        $start = $offset;
        while ($offset + 2 <= strlen($data)) {
            if (self::take($data, $offset, 2) === "\0\0") {
                return self::text(substr($data, $start, $offset - $start - 2));
            }
        }
        throw new \UnexpectedValueException('Unterminated ASF string');
    }

    private static function text(string $data): string
    {
        if (strlen($data) % 2 !== 0) {
            throw new \UnexpectedValueException('Invalid ASF UTF-16 length');
        }
        return rtrim(mb_convert_encoding($data, 'UTF-8', 'UTF-16LE'), "\0");
    }

    private static function take(string $data, int &$offset, int $length): string
    {
        if ($length < 0 || $length > strlen($data) - $offset) {
            throw new \UnexpectedValueException('Truncated ASF field');
        }
        $value = substr($data, $offset, $length);
        $offset += $length;
        return $value;
    }

    /** @param resource $handle */
    private function read($handle, int $length): string
    {
        if ($length > self::MAX_METADATA_BYTES - $this->bytesRead) {
            throw new \UnexpectedValueException('ASF metadata exceeds size limit');
        }
        $this->bytesRead += $length;
        $data = $length === 0 ? '' : fread($handle, $length);
        if ($data === false || strlen($data) !== $length) {
            throw new \UnexpectedValueException('Truncated ASF file');
        }
        return $data;
    }

    private static function uint16(string $data): int
    {
        return unpack('v', $data)[1];
    }

    private static function uint32(string $data): int
    {
        return unpack('V', $data)[1];
    }

    private static function uint64(string $data): int
    {
        $parts = unpack('Vlow/Vhigh', $data);
        if ($parts['high'] > 0x7fffffff) {
            throw new \UnexpectedValueException('ASF integer exceeds supported range');
        }
        return $parts['high'] * 4_294_967_296 + $parts['low'];
    }
}
