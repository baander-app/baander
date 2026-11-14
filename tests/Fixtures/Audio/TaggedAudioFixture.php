<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Audio;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Writes short audio files tagged byte for byte as tagging tools write them: FLAC with a Vorbis
 * comment block whose fields may repeat, and MP3 with an ID3v2.3 tag. FFmpeg encodes a sine
 * tone of each length once, without tags; the tags are spliced in afterwards, because FFmpeg's
 * own writers cannot repeat a Vorbis field or write ID3v2.3 frames such as a TLEN in seconds or
 * a value that ends in a NUL byte. Writing a file again with other tags keeps its audio.
 *
 * Each file also carries the fixture's salt, last, in a field the tag readers ignore. The catalog
 * matches songs by file content across libraries, so files with the same tags and audio from two
 * fixtures still differ.
 */
final class TaggedAudioFixture
{
    private const string FLAC_SIGNATURE = 'fLaC';
    private const int STREAMINFO = 0;
    private const int VORBIS_COMMENT = 4;
    private const int LAST_BLOCK = 0x80;
    private const string SALT_FIELD = 'BAANDER_FIXTURE';

    /** @var array<string, string> the untagged audio of each format and length */
    private array $encoded = [];

    public function __construct(private readonly string $salt)
    {
    }

    /**
     * Writes a FLAC file with one Vorbis comment block, right after STREAMINFO.
     *
     * @param list<array{string, string}> $comments field and value pairs in tag order; a field may repeat
     */
    public function writeFlac(string $path, float $seconds, array $comments): void
    {
        $comments[] = [self::SALT_FIELD, $this->salt];
        $this->write($path, self::withVorbisComments($this->encoded('flac', $seconds), $comments));
    }

    /**
     * Writes an MP3 file that starts with an ID3v2.3 tag of ISO-8859-1 text frames.
     *
     * @param list<array{string, string}> $frames frame ID and text pairs in tag order; a TXXX text is
     *                                            its description, a NUL byte and its value
     */
    public function writeMp3(string $path, float $seconds, array $frames): void
    {
        $frames[] = ['TXXX', self::SALT_FIELD . "\0" . $this->salt];
        $this->write($path, self::id3v23Tag($frames) . $this->encoded('mp3', $seconds));
    }

    /** The encoders of the FFmpeg build on the path, as `ffmpeg -encoders` lists them. */
    public static function encoders(): string
    {
        return new Process(['ffmpeg', '-hide_banner', '-encoders'])->mustRun()->getOutput();
    }

    /** @param 'flac'|'mp3' $format */
    private function encoded(string $format, float $seconds): string
    {
        return $this->encoded[$format . '@' . $seconds] ??= self::encode($format, $seconds);
    }

    /**
     * A sine tone of the given length, without any tag.
     *
     * @param 'flac'|'mp3' $format
     */
    private static function encode(string $format, float $seconds): string
    {
        $codec = match ($format) {
            'flac' => ['-c:a', 'flac'],
            // The MP3 muxer takes its ID3v2 switch as a version; 0 writes no tag. Its Xing header
            // stays, so the stream states its length as players and ffprobe read it.
            'mp3' => ['-c:a', 'libmp3lame', '-b:a', '128k', '-id3v2_version', '0'],
        };
        $target = sprintf('%s/baander-tagged-audio-%s.%s', sys_get_temp_dir(), bin2hex(random_bytes(8)), $format);
        try {
            new Process([
                'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
                '-f', 'lavfi', '-i', 'sine=frequency=440:duration=' . $seconds,
                '-ac', '2', '-ar', '44100', '-map_metadata', '-1', ...$codec, $target,
            ])->mustRun();
            $audio = file_get_contents($target);
        } finally {
            if (is_file($target)) {
                unlink($target);
            }
        }
        if (!is_string($audio)) {
            throw new RuntimeException(sprintf('FFmpeg wrote no %s audio.', $format));
        }
        if ($format === 'mp3' && !($audio[0] === "\xFF" && (ord($audio[1]) & 0xE0) === 0xE0)) {
            throw new RuntimeException('FFmpeg wrote the MP3 with a tag in front of its first audio frame.');
        }

        return $audio;
    }

    /**
     * The FLAC file with its Vorbis comment block replaced by one of the given comments, placed
     * right after STREAMINFO. The other metadata blocks and the audio frames stay as they are.
     *
     * @param list<array{string, string}> $comments
     */
    private static function withVorbisComments(string $flac, array $comments): string
    {
        if (!str_starts_with($flac, self::FLAC_SIGNATURE)) {
            throw new RuntimeException('FFmpeg wrote no FLAC stream.');
        }

        $blocks = [];
        $offset = strlen(self::FLAC_SIGNATURE);
        do {
            $header = ord($flac[$offset]);
            $length = ord($flac[$offset + 1]) << 16 | ord($flac[$offset + 2]) << 8 | ord($flac[$offset + 3]);
            $type = $header & 0x7F;
            if ($type !== self::VORBIS_COMMENT) {
                $blocks[] = [$type, substr($flac, $offset + 4, $length)];
            }
            $offset += 4 + $length;
        } while (($header & self::LAST_BLOCK) === 0);
        if (($blocks[0][0] ?? null) !== self::STREAMINFO) {
            throw new RuntimeException('The FLAC stream does not start with STREAMINFO.');
        }
        array_splice($blocks, 1, 0, [[self::VORBIS_COMMENT, self::vorbisComment($comments)]]);

        $metadata = '';
        $last = array_key_last($blocks);
        foreach ($blocks as $index => [$type, $data]) {
            $metadata .= chr(($index === $last ? self::LAST_BLOCK : 0) | $type) . substr(pack('N', strlen($data)), 1) . $data;
        }

        return self::FLAC_SIGNATURE . $metadata . substr($flac, $offset);
    }

    /** @param list<array{string, string}> $comments */
    private static function vorbisComment(array $comments): string
    {
        $vendor = 'Baander test';
        $block = pack('V', strlen($vendor)) . $vendor . pack('V', count($comments));
        foreach ($comments as [$field, $value]) {
            $entry = $field . '=' . $value;
            $block .= pack('V', strlen($entry)) . $entry;
        }

        return $block;
    }

    /** @param list<array{string, string}> $frames */
    private static function id3v23Tag(array $frames): string
    {
        $body = '';
        foreach ($frames as [$id, $text]) {
            // The leading 0x00 marks the text as ISO-8859-1.
            $data = "\x00" . $text;
            $body .= $id . pack('N', strlen($data)) . "\x00\x00" . $data;
        }

        return "ID3\x03\x00\x00" . self::syncsafe(strlen($body)) . $body;
    }

    private static function syncsafe(int $size): string
    {
        return chr(($size >> 21) & 0x7F) . chr(($size >> 14) & 0x7F) . chr(($size >> 7) & 0x7F) . chr($size & 0x7F);
    }

    private function write(string $path, string $bytes): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create %s.', $directory));
        }
        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new RuntimeException(sprintf('Cannot write %s.', $path));
        }
    }
}
