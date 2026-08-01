<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\ValueObject;

use App\Transcode\Domain\ValueObject\SubtitleTrackInfo;
use PHPUnit\Framework\TestCase;

final class SubtitleTrackInfoTest extends TestCase
{
    public function testConstructorStoresAllFields(): void
    {
        $info = new SubtitleTrackInfo(
            language: 'en',
            codec: 'subrip',
            title: 'English SDH',
            isDefault: true,
        );

        $this->assertSame('en', $info->language);
        $this->assertSame('subrip', $info->codec);
        $this->assertSame('English SDH', $info->title);
        $this->assertTrue($info->isDefault);
    }

    public function testFromProbeStreamExtractsFields(): void
    {
        $stream = [
            'codec_name' => 'subrip',
            'tags' => [
                'language' => 'fr',
                'title' => 'French',
                'default' => 'true',
            ],
        ];

        $info = SubtitleTrackInfo::fromProbeStream($stream);

        $this->assertSame('fr', $info->language);
        $this->assertSame('subrip', $info->codec);
        $this->assertSame('French', $info->title);
        $this->assertTrue($info->isDefault);
    }

    public function testFromProbeStreamDefaults(): void
    {
        $info = SubtitleTrackInfo::fromProbeStream([]);

        $this->assertSame('und', $info->language);
        $this->assertSame('unknown', $info->codec);
        $this->assertFalse($info->isDefault);
    }

    public function testFromSerializedRoundTrips(): void
    {
        $original = new SubtitleTrackInfo('es', 'ass', 'Spanish', false);
        $data = $original->jsonSerialize();
        $restored = SubtitleTrackInfo::fromSerialized($data);

        $this->assertTrue($original->equals($restored));
    }

    public function testEqualsReturnsTrueForIdenticalInstances(): void
    {
        $a = new SubtitleTrackInfo('en', 'subrip', 'English', true);
        $b = new SubtitleTrackInfo('en', 'subrip', 'English', true);

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsReturnsFalseWhenLanguageDiffers(): void
    {
        $a = new SubtitleTrackInfo('en', 'subrip', 'English', true);
        $b = new SubtitleTrackInfo('fr', 'subrip', 'English', true);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseWhenCodecDiffers(): void
    {
        $a = new SubtitleTrackInfo('en', 'subrip', 'English', false);
        $b = new SubtitleTrackInfo('en', 'ass', 'English', false);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseWhenTitleDiffers(): void
    {
        $a = new SubtitleTrackInfo('en', 'subrip', 'English', false);
        $b = new SubtitleTrackInfo('en', 'subrip', 'English SDH', false);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseWhenIsDefaultDiffers(): void
    {
        $a = new SubtitleTrackInfo('en', 'subrip', 'English', true);
        $b = new SubtitleTrackInfo('en', 'subrip', 'English', false);

        $this->assertFalse($a->equals($b));
    }

    public function testGetDisplayNameReturnsTitleWhenDifferentFromLanguage(): void
    {
        $info = new SubtitleTrackInfo('en', 'subrip', 'English SDH', false);

        $this->assertSame('English SDH', $info->getDisplayName());
    }

    public function testGetDisplayNameReturnsLanguageWhenTitleMatchesLanguage(): void
    {
        $info = new SubtitleTrackInfo('en', 'subrip', 'en', false);

        $this->assertSame('en', $info->getDisplayName());
    }

    public function testGetDisplayNameReturnsLanguageWhenTitleEmpty(): void
    {
        $info = new SubtitleTrackInfo('en', 'subrip', '', false);

        $this->assertSame('en', $info->getDisplayName());
    }

    public function testJsonSerializeReturnsAllFields(): void
    {
        $info = new SubtitleTrackInfo('en', 'subrip', 'English', true);
        $data = $info->jsonSerialize();

        $this->assertSame([
            'language' => 'en',
            'codec' => 'subrip',
            'title' => 'English',
            'isDefault' => true,
        ], $data);
    }
}
