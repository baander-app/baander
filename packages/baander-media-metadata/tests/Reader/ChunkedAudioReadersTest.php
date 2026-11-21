<?php

declare(strict_types=1);

namespace Baander\MediaMetadata\Tests\Reader;

use Baander\MediaMetadata\Reader\AacReader;
use Baander\MediaMetadata\Reader\AiffReader;
use Baander\MediaMetadata\Reader\WavReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ChunkedAudioReadersTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            unlink($path);
        }
    }

    public function testStandardPcmAndEmbeddedId3OverrideInfoWithoutOverridingDuration(): void
    {
        $body = $this->chunk('JUNK', 'x')
            . $this->chunk('fmt ', pack('vvVVvv', 1, 2, 44100, 176400, 4, 16))
            . $this->chunk('LIST', 'INFO' . $this->chunk('INAM', "Legacy\0") . $this->chunk('IART', "Artist\0"))
            . $this->chunk('id3 ', $this->id3())
            . $this->chunk('data', str_repeat("\0", 1764));
        $metadata = (new WavReader(new NullLogger()))->read($this->file('RIFF' . pack('V', strlen($body) + 4) . 'WAVE' . $body));
        self::assertSame('Tagged', $metadata->getTitle());
        self::assertSame('Artist', $metadata->getArtist());
        self::assertSame(44100, $metadata->getSampleRate());
        self::assertSame(1411200, $metadata->getBitrate());
        self::assertSame(0.01, $metadata->getDuration());
        self::assertCount(1, $metadata->getPictures());
    }

    public function testAiffReadsExtendedRateTextAndId3(): void
    {
        $body = $this->chunk('COMM', pack('nNn', 2, 441, 16) . hex2bin('400eac44000000000000'), true)
            . $this->chunk('NAME', 'Legacy', true)
            . $this->chunk('AUTH', 'Author', true)
            . $this->chunk('ID3 ', $this->id3(), true)
            . $this->chunk('SSND', pack('NN', 0, 0) . str_repeat("\0", 1764), true);
        $metadata = (new AiffReader(new NullLogger()))->read($this->file('FORM' . pack('N', strlen($body) + 4) . 'AIFF' . $body));
        self::assertSame('Tagged', $metadata->getTitle());
        self::assertSame('Author', $metadata->getArtist());
        self::assertSame(44100, $metadata->getSampleRate());
        self::assertSame(2, $metadata->getChannels());
        self::assertSame(0.01, $metadata->getDuration());
        self::assertCount(1, $metadata->getPictures());
    }

    public function testAifcDoesNotInventCompressedBitrate(): void
    {
        $body = $this->chunk('COMM', pack('nNn', 1, 22050, 16) . hex2bin('400dac44000000000000') . 'ulaw' . "\0\0", true);
        $metadata = (new AiffReader(new NullLogger()))->read($this->file('FORM' . pack('N', strlen($body) + 4) . 'AIFC' . $body));
        self::assertSame(22050, $metadata->getSampleRate());
        self::assertSame(1.0, $metadata->getDuration());
        self::assertNull($metadata->getBitrate());
    }

    public function testAacReadsLeadingId3AndAdtsProperties(): void
    {
        $metadata = (new AacReader(new NullLogger()))->read($this->file($this->id3() . "\xff\xf1\x50\x80\x01\x7f\xfc" . 'data'));
        self::assertSame('Tagged', $metadata->getTitle());
        self::assertSame(44100, $metadata->getSampleRate());
        self::assertSame(2, $metadata->getChannels());
        self::assertNull($metadata->getDuration());
        self::assertCount(1, $metadata->getPictures());
    }

    public function testMalformedContainersAndMpegSyncProduceNoTechnicalProperties(): void
    {
        foreach ([new WavReader(new NullLogger()), new AiffReader(new NullLogger()), new AacReader(new NullLogger())] as $reader) {
            $metadata = $reader->read($this->file("\xff\xfb\x90\x00"));
            self::assertNull($metadata->getSampleRate());
        }
        $body = $this->chunk('fmt ', pack('vvVVvv', 1, 2, 44100, 176400, 4, 16)) . 'data' . pack('V', 999999);
        $metadata = (new WavReader(new NullLogger()))->read($this->file('RIFF' . pack('V', strlen($body) + 4) . 'WAVE' . $body));
        self::assertNull($metadata->getDuration());
    }

    public function testWaveSeeksPastLargeSparseAudioAndFindsTrailingTags(): void
    {
        $path = $this->file('');
        $handle = fopen($path, 'wb');
        $audioSize = 512 * 1024 * 1024;
        $format = $this->chunk('fmt ', pack('vvVVvv', 1, 2, 44100, 176400, 4, 16));
        $tag = $this->chunk('id3 ', $this->id3());
        fwrite($handle, 'RIFF' . pack('V', 4 + strlen($format) + 8 + $audioSize + strlen($tag)) . 'WAVE' . $format . 'data' . pack('V', $audioSize));
        fseek($handle, $audioSize, SEEK_CUR);
        fwrite($handle, $tag);
        fclose($handle);
        $before = memory_get_usage(true);
        $metadata = (new WavReader(new NullLogger()))->read($path);
        self::assertSame('Tagged', $metadata->getTitle());
        self::assertEqualsWithDelta($audioSize / 176400, $metadata->getDuration(), 0.00001);
        self::assertLessThan(16 * 1024 * 1024, memory_get_usage(true) - $before);
    }

    public function testDeclaredContainerBoundsExcludeTrailingTags(): void
    {
        $body = $this->chunk('NAME', 'Inside', true);
        $metadata = (new AiffReader(new NullLogger()))->read($this->file('FORM' . pack('N', 4 + strlen($body)) . 'AIFF' . $body . $this->chunk('ID3 ', $this->id3(), true)));
        self::assertSame('Inside', $metadata->getTitle());
        self::assertSame([], $metadata->getPictures());
    }

    public function testAacRejectsTruncatedFramesAndReservedSampleRate(): void
    {
        $reader = new AacReader(new NullLogger());
        foreach (["\xff\xf1\x50\x80\x01\x7f\xfc", "\xff\xf1\x7c\x80\x01\x7f\xfcdata"] as $data) {
            self::assertNull($reader->read($this->file($data))->getSampleRate());
        }
    }

    private function file(string $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'audio-reader-');
        file_put_contents($path, $data);
        $this->paths[] = $path;
        return $path;
    }

    private function chunk(string $id, string $data, bool $bigEndian = false): string
    {
        return $id . pack($bigEndian ? 'N' : 'V', strlen($data)) . $data . (strlen($data) % 2 ? "\0" : '');
    }

    private function id3(): string
    {
        $frames = '';
        foreach (['TIT2' => "\x03Tagged", 'TLEN' => "\x0399999", 'APIC' => "\0image/png\0\x03\0picture"] as $id => $data) {
            $frames .= $id . pack('N', strlen($data)) . "\0\0" . $data;
        }
        return 'ID3' . "\x03\0\0\0\0\0" . chr(strlen($frames)) . $frames;
    }
}
