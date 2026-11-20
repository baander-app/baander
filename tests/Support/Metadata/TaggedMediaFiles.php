<?php

declare(strict_types=1);

namespace App\Tests\Support\Metadata;

use App\Metadata\Infrastructure\Matching\MatchingStrategy;
use App\Metadata\Infrastructure\Matching\Validator\AlbumValidator;
use App\Metadata\Infrastructure\Matching\Validator\ArtistValidator;
use App\Metadata\Infrastructure\Matching\Validator\SongValidator;
use App\Metadata\Infrastructure\Reader\MediaFileMetadata;
use App\Metadata\Infrastructure\Reader\MetadataContentReader;
use Baander\MediaMetadata\FlacReader;
use Baander\MediaMetadata\FormatDetector;
use Baander\MediaMetadata\Id3Reader;
use Baander\MediaMetadata\MetadataContentReader as PackageMetadataContentReader;
use Baander\MediaMetadata\OggReader;
use Baander\MediaMetadata\WavReader;
use Psr\Log\NullLogger;

/**
 * Small media files that carry tags, written to one temporary directory and removed with it.
 */
final class TaggedMediaFiles
{
    private readonly string $directory;
    /** @var list<string> */
    private array $paths = [];

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/baander-tagged-media-' . (random_bytes(8) |> bin2hex(...));
        if (!mkdir($this->directory, 0700)) {
            throw new \RuntimeException('Cannot create ' . $this->directory);
        }
    }

    /** The tag reading the extract and match routes and commands share, with the library scan's readers. */
    public static function reader(): MediaFileMetadata
    {
        $logger = new NullLogger();
        $formats = new FormatDetector();

        return new MediaFileMetadata(
            $formats,
            new MetadataContentReader(new PackageMetadataContentReader($formats, new Id3Reader($logger), new FlacReader($logger), new OggReader($logger), new WavReader($logger))),
            new MatchingStrategy(new ArtistValidator(), new AlbumValidator(), new SongValidator()),
        );
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** A path inside the directory where no file exists. */
    public function missing(): string
    {
        return $this->directory . '/missing.mp3';
    }

    /** An MPEG audio frame followed by an ID3v1 tag. */
    public function mp3(string $title = 'Test Song', string $artist = 'Test Artist', string $album = 'Test Album'): string
    {
        $tag = 'TAG'
            . str_pad($title, 30, "\x00")
            . str_pad($artist, 30, "\x00")
            . str_pad($album, 30, "\x00")
            . '2026'
            . str_repeat("\x00", 30)
            . "\xFF";

        return $this->write('song.mp3', "\xFF\xFB" . str_repeat("\x00", 254) . $tag);
    }

    /** A RIFF WAVE file whose LIST INFO chunk names the title (INAM) and artist (IART). */
    public function wav(string $title = 'Test Song', string $artist = 'Test Artist'): string
    {
        $info = 'INFO' . self::riffChunk('INAM', $title . "\x00") . self::riffChunk('IART', $artist . "\x00");
        $body = 'WAVE'
            . self::riffChunk('fmt ', pack('vvVVvv', 1, 1, 8000, 16000, 2, 16))
            . self::riffChunk('data', "\x00\x00\x00\x00")
            . self::riffChunk('LIST', $info);

        return $this->write('song.wav', self::riffChunk('RIFF', $body));
    }

    /** An Ogg Opus stream: the identification header on the first page, the comment header on the second. */
    public function opus(string $title = 'Test Song', string $artist = 'Test Artist'): string
    {
        $vendor = 'Baander test encoder';
        $comments = ['TITLE=' . $title, 'ARTIST=' . $artist];
        $tags = 'OpusTags' . pack('V', strlen($vendor)) . $vendor . pack('V', count($comments))
            . implode('', array_map(static fn (string $comment): string => pack('V', strlen($comment)) . $comment, $comments));
        $head = 'OpusHead' . "\x01\x02" . pack('v', 312) . pack('V', 48000) . pack('v', 0) . "\x00";

        return $this->write('song.opus', self::oggPage($head, 0, "\x02") . self::oggPage($tags, 1, "\x00"));
    }

    /** A file no tag reader recognises. */
    public function unsupported(): string
    {
        return $this->write('notes.txt', 'Not audio.');
    }

    public function remove(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->directory);
    }

    private function write(string $name, string $bytes): string
    {
        $path = $this->directory . '/' . $name;
        if (file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException('Cannot write ' . $path);
        }
        $this->paths[] = $path;

        return $path;
    }

    private static function riffChunk(string $id, string $data): string
    {
        $padding = strlen($data) % 2 === 1 ? "\x00" : '';

        return $id . pack('V', strlen($data)) . $data . $padding;
    }

    /** One Ogg page holding one packet shorter than 255 bytes. */
    private static function oggPage(string $packet, int $sequence, string $headerType): string
    {
        return 'OggS' . "\x00" . $headerType
            . str_repeat("\x00", 8)
            . pack('V', 1) . pack('V', $sequence) . pack('V', 0)
            . "\x01" . (strlen($packet) |> chr(...))
            . $packet;
    }
}
