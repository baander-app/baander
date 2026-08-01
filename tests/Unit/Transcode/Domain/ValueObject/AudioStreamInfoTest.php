<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\ValueObject;

use App\Transcode\Domain\ValueObject\AudioStreamInfo;
use PHPUnit\Framework\TestCase;

final class AudioStreamInfoTest extends TestCase
{
    public function testConstructorStoresAllFields(): void
    {
        $info = new AudioStreamInfo(
            language: 'en',
            codec: 'aac',
            channels: 6,
            sampleRate: 48_000,
            bitrate: 256_000,
            title: 'Surround',
            isDefault: true,
        );

        $this->assertSame('en', $info->language);
        $this->assertSame('aac', $info->codec);
        $this->assertSame(6, $info->channels);
        $this->assertSame(48_000, $info->sampleRate);
        $this->assertSame(256_000, $info->bitrate);
        $this->assertSame('Surround', $info->title);
        $this->assertTrue($info->isDefault);
    }

    public function testFromProbeStreamExtractsFields(): void
    {
        $stream = [
            'codec_name' => 'aac',
            'channels' => 2,
            'sample_rate' => 48_000,
            'bit_rate' => 128_000,
            'tags' => [
                'language' => 'en',
                'title' => 'English',
                'default' => 'true',
            ],
        ];

        $info = AudioStreamInfo::fromProbeStream($stream);

        $this->assertSame('en', $info->language);
        $this->assertSame('aac', $info->codec);
        $this->assertSame(2, $info->channels);
        $this->assertSame(48_000, $info->sampleRate);
        $this->assertSame(128_000, $info->bitrate);
        $this->assertSame('English', $info->title);
        $this->assertTrue($info->isDefault);
    }

    public function testFromProbeStreamDefaults(): void
    {
        $info = AudioStreamInfo::fromProbeStream([]);

        $this->assertSame('und', $info->language);
        $this->assertSame('unknown', $info->codec);
        $this->assertSame(2, $info->channels);
        $this->assertSame(48_000, $info->sampleRate);
        $this->assertSame(0, $info->bitrate);
        $this->assertFalse($info->isDefault);
    }

    public function testFromSerializedRoundTrips(): void
    {
        $original = new AudioStreamInfo('fr', 'opus', 2, 48_000, 96_000, 'French', false);
        $data = $original->jsonSerialize();
        $restored = AudioStreamInfo::fromSerialized($data);

        $this->assertTrue($original->equals($restored));
    }

    public function testEqualsReturnsTrueForIdenticalInstances(): void
    {
        $a = new AudioStreamInfo('en', 'aac', 2, 48_000, 128_000, 'English', true);
        $b = new AudioStreamInfo('en', 'aac', 2, 48_000, 128_000, 'English', true);

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsReturnsFalseWhenLanguageDiffers(): void
    {
        $a = new AudioStreamInfo('en', 'aac', 2, 48_000, 128_000, 'English', true);
        $b = new AudioStreamInfo('fr', 'aac', 2, 48_000, 128_000, 'English', true);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseWhenCodecDiffers(): void
    {
        $a = new AudioStreamInfo('en', 'aac', 2, 48_000, 128_000, '', false);
        $b = new AudioStreamInfo('en', 'opus', 2, 48_000, 128_000, '', false);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseWhenChannelsDiffer(): void
    {
        $a = new AudioStreamInfo('en', 'aac', 2, 48_000, 128_000, '', false);
        $b = new AudioStreamInfo('en', 'aac', 6, 48_000, 128_000, '', false);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseWhenSampleRateDiffers(): void
    {
        $a = new AudioStreamInfo('en', 'aac', 2, 48_000, 128_000, '', false);
        $b = new AudioStreamInfo('en', 'aac', 2, 44_100, 128_000, '', false);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseWhenBitrateDiffers(): void
    {
        $a = new AudioStreamInfo('en', 'aac', 2, 48_000, 128_000, '', false);
        $b = new AudioStreamInfo('en', 'aac', 2, 48_000, 256_000, '', false);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseWhenTitleDiffers(): void
    {
        $a = new AudioStreamInfo('en', 'aac', 2, 48_000, 128_000, 'Director', false);
        $b = new AudioStreamInfo('en', 'aac', 2, 48_000, 128_000, 'Commentary', false);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseWhenIsDefaultDiffers(): void
    {
        $a = new AudioStreamInfo('en', 'aac', 2, 48_000, 128_000, '', true);
        $b = new AudioStreamInfo('en', 'aac', 2, 48_000, 128_000, '', false);

        $this->assertFalse($a->equals($b));
    }

    public function testGetDisplayNameReturnsTitleWhenDifferentFromLanguage(): void
    {
        $info = new AudioStreamInfo('en', 'aac', 2, 48_000, 128_000, 'Director Commentary', false);

        $this->assertSame('Director Commentary', $info->getDisplayName());
    }

    public function testGetDisplayNameReturnsLanguageWhenTitleMatchesLanguage(): void
    {
        $info = new AudioStreamInfo('en', 'aac', 2, 48_000, 128_000, 'en', false);

        $this->assertSame('en', $info->getDisplayName());
    }

    public function testGetDisplayNameReturnsLanguageWhenTitleEmpty(): void
    {
        $info = new AudioStreamInfo('en', 'aac', 2, 48_000, 128_000, '', false);

        $this->assertSame('en', $info->getDisplayName());
    }

    public function testJsonSerializeReturnsAllFields(): void
    {
        $info = new AudioStreamInfo('en', 'aac', 2, 48_000, 128_000, 'English', true);
        $data = $info->jsonSerialize();

        $this->assertSame([
            'language' => 'en',
            'codec' => 'aac',
            'channels' => 2,
            'sampleRate' => 48_000,
            'bitrate' => 128_000,
            'title' => 'English',
            'isDefault' => true,
        ], $data);
    }
}
