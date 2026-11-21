<?php

declare(strict_types=1);

namespace Baander\MediaMetadata\Tests\Reader;

use Baander\MediaMetadata\Reader\AacReader;
use Baander\MediaMetadata\Reader\AiffReader;
use Baander\MediaMetadata\Reader\Id3Reader;
use Baander\MediaMetadata\Reader\WavReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class Id3MusicBrainzTest extends TestCase
{
    /** @param 'mp3'|'wav'|'aiff'|'aac' $container */
    #[DataProvider('containers')]
    public function testPreservesRecordingAndReleaseIdentifiers(string $container): void
    {
        $recording = 'b3e5c878-d1e4-4ba1-a659-a8341c6310ec';
        $release = 'a99e5c52-5d6c-42ed-a343-e510de9aeb54';
        $frames = self::frame('UFID', 'https://baander.app' . "\0unrelated")
            . self::frame('UFID', 'http://musicbrainz.org' . "\0" . $recording)
            . self::frame('TXXX', "\x03MusicBrainz Album Id\0" . $release);
        $length = strlen($frames);
        $tag = "ID3\x03\0\0" . pack('CCCC', ($length >> 21) & 127, ($length >> 14) & 127, ($length >> 7) & 127, $length & 127) . $frames;
        $logger = new NullLogger();
        [$reader, $bytes] = match ($container) {
            'mp3' => [new Id3Reader($logger), $tag],
            'wav' => [new WavReader($logger), 'RIFF' . pack('V', 12 + $length + 10 + (($length + 10) % 2)) . 'WAVE' . self::chunk('id3 ', $tag, false)],
            'aiff' => [new AiffReader($logger), 'FORM' . pack('N', 12 + $length + 10 + (($length + 10) % 2)) . 'AIFF' . self::chunk('ID3 ', $tag, true)],
            'aac' => [new AacReader($logger), $tag . "\xff\xf1\x50\x80\x01\x7f\xfcdata"],
        };
        $path = tempnam(sys_get_temp_dir(), 'baander-id3-mb-');
        file_put_contents($path, $bytes);
        try {
            $metadata = $reader->read($path);
            self::assertSame($recording, $metadata->getMbid());
            self::assertSame($release, $metadata->getMbAlbumId());
        } finally {
            unlink($path);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function containers(): iterable
    {
        foreach (['mp3', 'wav', 'aiff', 'aac'] as $container) {
            yield $container => [$container];
        }
    }

    private static function frame(string $id, string $data): string
    {
        return $id . pack('N', strlen($data)) . "\0\0" . $data;
    }

    private static function chunk(string $id, string $data, bool $bigEndian): string
    {
        return $id . pack($bigEndian ? 'N' : 'V', strlen($data)) . $data . (strlen($data) % 2 ? "\0" : '');
    }
}
