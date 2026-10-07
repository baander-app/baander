<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;

/**
 * transcode.enabled decides whether audio is transcoded at all, and
 * transcode.max_bitrate caps the bitrate of what is.
 */
final class AudioTranscodeToggleTest extends AudioStreamTestCase
{
    public function testWithTheDefaultsTheOriginalStreamsAsBefore(): void
    {
        $admin = $this->createAdminUser();

        $original = $this->request($admin, '');

        self::assertSame(200, $original->getStatusCode());
        self::assertSame('audio/flac', $original->getHeader('Content-Type'));
        self::assertSame(file_get_contents($this->sourceFile), $original->getContent());
    }

    public function testWithTranscodingOffATranscodeRequestIsRefusedAndStartsNoEncode(): void
    {
        $admin = $this->createAdminUser();

        $refused = $this->request($admin, 'format=mp3&bitrate=192000');

        self::assertSame(403, $refused->getStatusCode());
        $error = json_decode($refused->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($error);
        self::assertSame('Audio transcoding is turned off on this server.', $error['error']['message'] ?? null);
        self::assertSame([], $this->cachedFiles());
    }

    public function testARequestedBitrateAboveTheMaximumIsCapped(): void
    {
        $this->settings([TranscodeSettingDefinitions::ENABLED => true, TranscodeSettingDefinitions::MAX_BITRATE => 192]);
        $admin = $this->createAdminUser();

        $capped = $this->request($admin, 'format=mp3&bitrate=320000');

        self::assertSame(200, $capped->getStatusCode());
        self::assertSame(['codec' => 'mp3', 'bitrate' => 192000], $this->probe($capped->getContent()));
    }

    public function testATranscodeWithoutABitrateUsesTheMaximum(): void
    {
        $this->settings([TranscodeSettingDefinitions::ENABLED => true, TranscodeSettingDefinitions::MAX_BITRATE => 128]);
        $admin = $this->createAdminUser();

        $transcoded = $this->request($admin, 'format=mp3');

        self::assertSame(200, $transcoded->getStatusCode());
        self::assertSame(['codec' => 'mp3', 'bitrate' => 128000], $this->probe($transcoded->getContent()));
    }

    /**
     * @param array<string, bool|int|string> $values
     */
    private function settings(array $values): void
    {
        self::getContainer()->get(SystemSettingStoreInterface::class)->save($values);
    }
}
