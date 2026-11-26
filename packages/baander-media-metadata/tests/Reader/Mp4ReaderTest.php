<?php

declare(strict_types=1);

namespace Baander\MediaMetadata\Tests\Reader;

use Baander\MediaMetadata\Reader\Mp4Reader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class Mp4ReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    public function testReadsTypedTagsAndMultipleFreeformValuesWithoutChangingSource(): void
    {
        $tags = self::tag("\xA9nam", ' Song ') . self::tag("\xA9alb", 'Album')
            . self::tag("\xA9ART", 'Singer') . self::tag("\xA9ART", 'Guest')
            . self::tag('aART', 'Band') . self::tag('aART', 'Ensemble')
            . self::tag("\xA9day", '2026-11-21') . self::tag("\xA9gen", 'Rock')
            . self::tag("\xA9wrt", 'Composer') . self::tag("\xA9cmt", 'Comment')
            . self::tag('trkn', pack('nnnn', 0, 4, 12, 0), 0)
            . self::tag('disk', pack('nnn', 0, 2, 3), 0)
            . self::tag('tmpo', pack('n', 128), 21)
            . self::freeform('MusicBrainz Track Id', ['recording'])
            . self::freeform('MusicBrainz Album Id', ['release'])
            . self::freeform('MusicBrainz Artist Id', ['artist-1', 'artist-2'])
            . self::freeform('MusicBrainz Album Artist Id', ['album-artist-1', 'album-artist-2'])
            . self::freeform('ARTISTS', ['Singer', 'Guest'])
            . self::tag('covr', "\x89PNG\r\n\x1A\n", 14);
        $bytes = self::atom('ftyp', 'M4A ' . str_repeat("\0", 4)) . self::atom('moov', self::atom('udta', self::atom('meta', str_repeat("\0", 4) . self::atom('ilst', $tags))));
        $path = $this->file($bytes);
        $metadata = (new Mp4Reader(new NullLogger()))->read($path);

        self::assertSame('Song', $metadata->getTitle());
        self::assertSame('Album', $metadata->getAlbum());
        self::assertSame(['Singer', 'Guest'], $metadata->getArtists());
        self::assertSame(['Singer', 'Guest'], $metadata->getIndividualArtists());
        self::assertSame(['Band', 'Ensemble'], $metadata->getAlbumArtists());
        self::assertSame('recording', $metadata->getMbid());
        self::assertSame('release', $metadata->getMbAlbumId());
        self::assertSame(['artist-1', 'artist-2'], $metadata->getMbArtistIds());
        self::assertNull($metadata->getMbArtistId());
        self::assertSame(['album-artist-1', 'album-artist-2'], $metadata->getMbAlbumArtistIds());
        self::assertSame(2026, $metadata->getYear());
        self::assertSame(['Rock'], $metadata->getGenre());
        self::assertSame('Composer', $metadata->getComposer());
        self::assertSame('Comment', $metadata->getComment());
        self::assertSame(4, $metadata->getTrackNumber());
        self::assertSame(2, $metadata->getDiscNumber());
        self::assertSame(128, $metadata->getBpm());
        self::assertSame('image/png', $metadata->getPictures()[0]->getMimeType());
        self::assertSame($bytes, file_get_contents($path));
    }

    public function testReadsAudioTrackDurationAndAacPropertiesWhileSkippingVideo(): void
    {
        $sample = str_repeat("\0", 6) . pack('n', 1) . str_repeat("\0", 8)
            . pack('nnnnN', 2, 16, 0, 0, 44100 << 16)
            . self::atom('btrt', pack('NNN', 0, 192000, 128000));
        $bytes = self::atom('moov', self::track('vide', 'mp4a', $sample, 99000)
            . self::track('soun', 'mp4a', $sample, 441000));
        $metadata = (new Mp4Reader(new NullLogger()))->read($this->file($bytes));

        self::assertSame(44100, $metadata->getSampleRate());
        self::assertSame(2, $metadata->getChannels());
        self::assertSame(128000, $metadata->getBitrate());
        self::assertSame(10.0, $metadata->getDuration());
    }

    public function testReadsAlacConfigurationAndVersionOneDuration(): void
    {
        $config = pack('N', 4096) . pack('CCCCCCnNNN', 0, 24, 40, 10, 14, 6, 0, 0, 900000, 48000);
        $sample = str_repeat("\0", 6) . pack('n', 1) . str_repeat("\0", 8)
            . pack('nnnnN', 2, 16, 0, 0, 44100 << 16)
            . self::atom('alac', str_repeat("\0", 4) . $config);
        $mdhd = "\x01\0\0\0" . str_repeat("\0", 16) . pack('NNN', 48000, 0, 96000);
        $metadata = (new Mp4Reader(new NullLogger()))->read($this->file(self::atom('moov', self::track('soun', 'alac', $sample, 0, $mdhd))));

        self::assertSame(48000, $metadata->getSampleRate());
        self::assertSame(6, $metadata->getChannels());
        self::assertSame(900000, $metadata->getBitrate());
        self::assertSame(2.0, $metadata->getDuration());
    }

    public function testExtendedAndZeroSizedAtomsAndMalformedBoundsTerminate(): void
    {
        $valid = self::atom('moov', self::atom('meta', str_repeat("\0", 4) . self::atom('ilst', self::tag("\xA9nam", 'Survives'))));
        $extended = pack('Na4NN', 1, 'free', 0, 16);
        foreach ([$extended . $valid . pack('Na4', 0, 'mdat'), $valid . pack('Na4', 7, 'bad!'), $valid . pack('Na4NN', 1, 'free', 0xFFFFFFFF, 0xFFFFFFFF), $valid . pack('Na4', 999999, 'free')] as $bytes) {
            self::assertSame('Survives', (new Mp4Reader(new NullLogger()))->read($this->file($bytes))->getTitle());
        }
        $reader = new Mp4Reader(new NullLogger());
        $truncatedMoov = pack('Na4', 100, 'moov');
        self::assertNull($reader->read($this->file($truncatedMoov))->getTitle());
        self::assertNull((new Mp4Reader(new NullLogger()))->read('/missing/baander.app.m4a')->getTitle());
    }

    public function testReadsFilesProducedByFfmpegWithoutRuntimeEncoderDependency(): void
    {
        foreach (['aac' => 0.12133333333333333, 'alac' => 0.1] as $codec => $duration) {
            $path = __DIR__ . '/../Fixtures/tagged-' . $codec . '.m4a';
            $hash = hash_file('sha256', $path);
            $metadata = (new Mp4Reader(new NullLogger()))->read($path);

            self::assertSame('Encoded ' . strtoupper($codec), $metadata->getTitle());
            self::assertSame(['Baander'], $metadata->getArtists());
            self::assertSame(48000, $metadata->getSampleRate());
            self::assertSame(1, $metadata->getChannels());
            self::assertEqualsWithDelta($duration, $metadata->getDuration(), 0.00001);
            self::assertGreaterThan(0, $metadata->getBitrate());
            self::assertSame($hash, hash_file('sha256', $path));
        }
    }

    public function testSkipsLargeMediaAndOversizedMetadataBySeeking(): void
    {
        $path = $this->file(pack('Na4', 67108864, 'mdat'));
        $handle = fopen($path, 'r+b');
        self::assertNotFalse($handle);
        fseek($handle, 67108864);
        $oversized = pack('Na4', 16777233, "\xA9nam") . pack('Na4NN', 16777225, 'data', 1, 0);
        $valid = self::tag("\xA9nam", 'After oversized value');
        $ilstSize = 8 + strlen($oversized) + 16777209 + strlen($valid);
        $metaSize = 12 + $ilstSize;
        fwrite($handle, pack('Na4', $metaSize + 8, 'moov') . pack('Na4N', $metaSize, 'meta', 0) . pack('Na4', $ilstSize, 'ilst') . $oversized);
        fseek($handle, 16777209, SEEK_CUR);
        fwrite($handle, $valid);
        fclose($handle);

        self::assertSame('After oversized value', (new Mp4Reader(new NullLogger()))->read($path)->getTitle());
    }

    private static function track(string $handler, string $codec, string $sample, int $duration, ?string $mdhd = null): string
    {
        $mdhd ??= str_repeat("\0", 12) . pack('NN', 44100, $duration);

        return self::atom('trak', self::atom('mdia', self::atom('mdhd', $mdhd)
            . self::atom('hdlr', str_repeat("\0", 8) . $handler)
            . self::atom('minf', self::atom('stbl', self::atom('stsd', pack('NN', 0, 1) . self::atom($codec, $sample))))));
    }

    private static function atom(string $type, string $data): string
    {
        return pack('N', strlen($data) + 8) . $type . $data;
    }

    private static function tag(string $name, string $value, int $type = 1): string
    {
        return self::atom($name, self::atom('data', pack('NN', $type, 0) . $value));
    }

    /** @param list<string> $values */
    private static function freeform(string $name, array $values): string
    {
        $data = self::atom('mean', pack('N', 0) . 'com.apple.iTunes') . self::atom('name', pack('N', 0) . $name);
        foreach ($values as $value) {
            $data .= self::atom('data', pack('NN', 1, 0) . $value);
        }

        return self::atom('----', $data);
    }

    private function file(string $bytes): string
    {
        $file = tempnam(sys_get_temp_dir(), 'mp4-metadata-');
        self::assertNotFalse($file);
        $this->files[] = $file;
        file_put_contents($file, $bytes);

        return $file;
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            unlink($file);
        }
    }
}
