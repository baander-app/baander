<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Reader;

use Psr\Log\LoggerInterface;

/**
 * Low-level ID3v2/v1 tag parser.
 *
 * Reads ID3 metadata directly from the binary file:
 * - ID3v2: frame-based structure with text frames (T*), the MusicBrainz
 *   artist ID user text frames (TXXX), comments and APIC picture frames
 * - ID3v1: fixed-width 128-byte footer with basic fields
 *
 * Tag values come back as UTF-8 without terminators, byte-order marks or
 * surrounding whitespace, one entry per value; empty values are dropped.
 */
final class Id3Parser
{
    private const string ID3V2_SIGNATURE = 'ID3';
    private const string ID3V1_SIGNATURE = 'TAG';

    private const int ENCODING_ISO_8859_1 = 0;
    private const int ENCODING_UTF_16 = 1;
    private const int ENCODING_UTF_16BE = 2;

    private const array TEXT_FRAMES = [
        'TIT2' => 'TITLE',
        'TPE1' => 'ARTIST',
        'TALB' => 'ALBUM',
        'TPE2' => 'ALBUMARTIST',
        'TCON' => 'GENRE',
        'TYER' => 'YEAR',
        'TDRC' => 'YEAR',
        'TRCK' => 'TRACKNUMBER',
        'TPOS' => 'DISCNUMBER',
        'TBPM' => 'BPM',
        'TCOM' => 'COMPOSER',
        'TLEN' => 'LENGTH',
    ];

    /** User text frames kept, by lowercase description, as Picard names them. */
    private const array USER_TEXT_FRAMES = [
        'musicbrainz artist id' => 'MUSICBRAINZ_ARTISTID',
        'musicbrainz album artist id' => 'MUSICBRAINZ_ALBUMARTISTID',
    ];

    private bool $isValid = false;
    private ?string $version = null;
    /** @var array<string, list<string>> */
    private array $tags = [];
    /** @var list<array{type: int, mimeType: string, description: string, imageData: string, imageSize: int}> */
    private array $pictures = [];

    public function __construct(
        private readonly string $filePath,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function parse(): void
    {
        $handle = @fopen($this->filePath, 'rb');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open ID3 file: {$this->filePath}");
        }

        try {
            $this->parseId3v2($handle);

            if (!$this->isValid) {
                $this->parseId3v1($handle);
            }

            if (!$this->isValid) {
                throw new \RuntimeException("No ID3 tags found in: {$this->filePath}");
            }

            $this->logger->debug('Id3Parser: parsed ID3 file', [
                'path' => $this->filePath,
                'version' => $this->version,
                'tags' => count($this->tags),
                'pictures' => count($this->pictures),
            ]);
        } finally {
            fclose($handle);
        }
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    /** @return array<string, list<string>> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @return list<array{type: int, mimeType: string, description: string, imageData: string, imageSize: int}> */
    public function getPictures(): array
    {
        return $this->pictures;
    }

    // ---- Private methods ----

    /**
     * @param resource $handle
     */
    private function parseId3v2($handle): void
    {
        $signature = fread($handle, 3);

        if ($signature !== self::ID3V2_SIGNATURE) {
            return;
        }

        $majorVersion = ord(fread($handle, 1));
        $minorVersion = ord(fread($handle, 1));
        $this->version = "ID3v2.{$majorVersion}.{$minorVersion}";

        // Skip flags (1 byte)
        fread($handle, 1);

        // Read tag size (syncsafe integer)
        $sizeBytes = fread($handle, 4);
        $tagSize = $this->syncSafeToInt($sizeBytes);

        $this->logger->debug('Id3Parser: found ID3v2 tag', [
            'version' => $this->version,
            'size' => $tagSize,
        ]);

        fseek($handle, 10); // Skip header
        $tagData = fread($handle, $tagSize);

        $this->parseFrames($tagData, $majorVersion);

        $this->isValid = true;
    }

    private function parseFrames(string $tagData, int $majorVersion): void
    {
        $offset = 0;
        $tagSize = strlen($tagData);

        // A frame header is 10 bytes (4 id + 4 size + 2 flags). Stop once fewer
        // than 10 bytes remain rather than reading past the buffer.
        while ($offset + 10 <= $tagSize) {
            if (ord($tagData[$offset]) === 0) {
                break; // Padding
            }

            $frameId = substr($tagData, $offset, 4);
            $offset += 4;

            // Frame size: ID3v2.4 encodes it syncsafe (7 bits/byte); ID3v2.3 uses
            // plain 32-bit big-endian. Decoding v2.4 as big-endian inflates the
            // size ~16x, overshoots the offset, and drops every frame after the
            // first — so most v2.4 tags came back with only one frame parsed.
            $sizeBytes = substr($tagData, $offset, 4);
            $offset += 4;
            $frameSize = $majorVersion >= 4
                ? $this->syncSafeToInt($sizeBytes)
                : ord($sizeBytes[0]) << 24
                    | ord($sizeBytes[1]) << 16
                    | ord($sizeBytes[2]) << 8
                    | ord($sizeBytes[3]);

            // Skip flags (2 bytes)
            $offset += 2;

            if ($offset + $frameSize > $tagSize) {
                break; // Truncated/corrupt frame — stop instead of reading past the buffer
            }

            $frameData = substr($tagData, $offset, $frameSize);
            $offset += $frameSize;

            if ($frameId === 'APIC') {
                $this->parsePictureFrame($frameData);
            } elseif ($frameId === 'COMM') {
                $this->parseCommentFrame($frameData, $majorVersion);
            } elseif ($frameId === 'TXXX') {
                $this->parseUserTextFrame($frameData, $majorVersion);
            } elseif (str_starts_with($frameId, 'T')) {
                $this->parseTextFrame($frameId, $frameData, $majorVersion);
            }
        }
    }

    private function parseTextFrame(string $frameId, string $data, int $majorVersion): void
    {
        $strings = $this->decodeStrings(substr($data, 1), ord($data[0]));

        $this->addTag(self::TEXT_FRAMES[$frameId] ?? $frameId, self::values($strings, $majorVersion));
    }

    /**
     * Parse a TXXX (user text) frame and keep it when its description is known.
     *
     * Structure: encoding (1) + description (terminated) + value(s).
     */
    private function parseUserTextFrame(string $data, int $majorVersion): void
    {
        $strings = $this->decodeStrings(substr($data, 1), ord($data[0]));
        $tagName = self::USER_TEXT_FRAMES[strtolower(array_shift($strings))] ?? null;

        if ($tagName === null) {
            return;
        }

        $values = self::values($strings, $majorVersion);
        // Picard joins several IDs with "/" in ID3v2.3; a MusicBrainz ID never contains one.
        $ids = explode('/', implode('/', $values));

        $this->addTag($tagName, array_map(trim(...), $ids) |> self::nonEmpty(...));
    }

    /**
     * Parse a COMM (comment) frame.
     *
     * Structure: encoding (1) + language (3) + short description (terminated) + full text.
     */
    private function parseCommentFrame(string $data, int $majorVersion): void
    {
        if (strlen($data) < 5) {
            return;
        }

        $strings = $this->decodeStrings(substr($data, 4), ord($data[0]));
        array_shift($strings);

        $this->addTag('COMMENT', self::values($strings, $majorVersion));
    }

    private function parsePictureFrame(string $data): void
    {
        $offset = 0;
        $encoding = ord($data[$offset]);
        $offset += 1;

        // MIME type (null-terminated)
        $mimeEnd = strpos($data, "\0", $offset);
        if ($mimeEnd === false) {
            return;
        }

        $mimeType = substr($data, $offset, $mimeEnd - $offset);
        $offset = $mimeEnd + 1;

        // Picture type (1 byte)
        $pictureType = ord($data[$offset]);
        $offset += 1;

        // Description (terminated in its own encoding)
        $descEnd = $this->findTerminator($data, $encoding, $offset);
        if ($descEnd === null) {
            return;
        }

        $description = $this->decodeText(substr($data, $offset, $descEnd - $offset), $encoding);
        $offset = $descEnd + (self::isUtf16($encoding) ? 2 : 1);

        $imageData = substr($data, $offset);

        $this->pictures[] = [
            'type' => $pictureType,
            'mimeType' => $mimeType,
            'description' => $description,
            'imageData' => $imageData,
            'imageSize' => strlen($imageData),
        ];
    }

    /**
     * @param resource $handle
     */
    private function parseId3v1($handle): void
    {
        fseek($handle, -128, SEEK_END);
        $signature = fread($handle, 3);

        if ($signature !== self::ID3V1_SIGNATURE) {
            return;
        }

        $this->version = 'ID3v1';

        $fields = [
            'TITLE' => 30,
            'ARTIST' => 30,
            'ALBUM' => 30,
            'YEAR' => 4,
            'COMMENT' => 30,
        ];

        foreach ($fields as $name => $length) {
            $value = $this->decodeText(trim(fread($handle, $length)), self::ENCODING_ISO_8859_1);
            if ($value !== '') {
                $this->tags[$name][] = $value;
            }
        }

        // Track number (optional, in v1.1)
        // ID3v1.1 layout (last 3 bytes): track (1), zero (1), genre (1)
        fseek($handle, -3, SEEK_END);
        $track = ord(fread($handle, 1));

        if ($track !== 0) {
            $this->tags['TRACKNUMBER'][] = (string) $track;
        }

        $this->isValid = true;
    }

    /** @param list<string> $values */
    private function addTag(string $name, array $values): void
    {
        if ($values !== []) {
            $this->tags[$name] = [...$this->tags[$name] ?? [], ...$values];
        }
    }

    /**
     * Decode terminator-separated strings, keeping empty ones so a leading
     * description keeps its position.
     *
     * The whole body is decoded first, so a UTF-16 terminator is only ever
     * matched on a code-unit boundary.
     *
     * @return non-empty-list<string>
     */
    private function decodeStrings(string $data, int $encoding): array
    {
        return array_map(self::cleanText(...), explode("\0", $this->decodeText($data, $encoding)));
    }

    private function decodeText(string $data, int $encoding): string
    {
        return match ($encoding) {
            self::ENCODING_ISO_8859_1 => mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1'),
            // With a byte-order mark; mbstring reads big-endian when it is missing.
            self::ENCODING_UTF_16 => mb_convert_encoding($data, 'UTF-8', 'UTF-16'),
            self::ENCODING_UTF_16BE => mb_convert_encoding($data, 'UTF-8', 'UTF-16BE'),
            default => $data, // UTF-8
        };
    }

    /**
     * Offset of the first terminator at or after $from: one NUL byte, or for
     * UTF-16 a NUL pair on a code-unit boundary.
     */
    private function findTerminator(string $data, int $encoding, int $from): ?int
    {
        if (!self::isUtf16($encoding)) {
            $position = strpos($data, "\0", $from);

            return $position === false ? null : $position;
        }

        for ($position = strpos($data, "\0\0", $from); $position !== false; $position = strpos($data, "\0\0", $position + 1)) {
            if (($position - $from) % 2 === 0) {
                return $position;
            }
        }

        return null;
    }

    private static function isUtf16(int $encoding): bool
    {
        return $encoding === self::ENCODING_UTF_16 || $encoding === self::ENCODING_UTF_16BE;
    }

    /** Drop byte-order marks (a later UTF-16 value carries its own) and surrounding whitespace. */
    private static function cleanText(string $text): string
    {
        return str_replace("\u{FEFF}", '', $text) |> trim(...);
    }

    /**
     * The non-empty values among a frame's strings. ID3v2.4 separates several
     * values with a terminator; earlier versions ignore what follows the first.
     *
     * @param list<string> $strings
     *
     * @return list<string>
     */
    private static function values(array $strings, int $majorVersion): array
    {
        return ($majorVersion >= 4 ? $strings : array_slice($strings, 0, 1)) |> self::nonEmpty(...);
    }

    /**
     * @param list<string> $strings
     *
     * @return list<string>
     */
    private static function nonEmpty(array $strings): array
    {
        return array_values(array_filter($strings, static fn (string $value): bool => $value !== ''));
    }

    private function syncSafeToInt(string $bytes): int
    {
        $result = 0;

        foreach (str_split($bytes) as $i => $byte) {
            $result |= ord($byte) << (7 * (3 - $i));
        }

        return $result;
    }
}
