<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Infrastructure\FFmpeg\WorkerEncoderCapabilitiesSnapshotStore;
use PHPUnit\Framework\TestCase;

final class WorkerEncoderCapabilitiesSnapshotStoreTest extends TestCase
{
    private string $directory;
    private string $path;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-capabilities-' . bin2hex(random_bytes(8));
        $this->path = $this->directory . '/capabilities.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testMissingSnapshotIsUnavailableWithoutCreatingStorage(): void
    {
        self::assertNull(new WorkerEncoderCapabilitiesSnapshotStore($this->path)->read());
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testPublishedWorkerEvidenceRoundTripsItsProfileAndProvenance(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_vaapi', '/dev/dri/renderD128');
        $store = new WorkerEncoderCapabilitiesSnapshotStore($this->path);
        $store->publish($profile, 1.25, 'worker-baander');
        $snapshot = $store->read();
        self::assertNotNull($snapshot);
        self::assertSame($profile->jsonSerialize(), $snapshot->profile->jsonSerialize());
        self::assertSame(1.25, $snapshot->bitrateMultiplier);
        self::assertSame('worker-baander', $snapshot->workerIdentity);
        self::assertGreaterThanOrEqual(time() - 5, $snapshot->observedAt);
    }

    public function testHistoricalObservationRemainsEvidenceWithoutClaimingLiveness(): void
    {
        $store = new WorkerEncoderCapabilitiesSnapshotStore($this->path);
        $store->publish(EncoderProfile::software(), 1.0, 'worker-baander');
        $published = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
        $published['observedAt'] = time() - 86401;
        file_put_contents($this->path, json_encode($published, JSON_THROW_ON_ERROR));
        self::assertSame($published['observedAt'], $store->read()?->observedAt);
    }

    public function testMalformedIncompleteAndFutureDatedEvidenceIsUnavailable(): void
    {
        $store = new WorkerEncoderCapabilitiesSnapshotStore($this->path);
        $store->publish(EncoderProfile::software(), 1.0, 'worker-baander');
        $published = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
        $cases = ['{', '{}', json_encode([...$published, 'version' => 2]), json_encode([...$published, 'observedAt' => time() + 3600]), json_encode([...$published, 'profile' => ['encoder' => 'libx265']]), json_encode([...$published, 'profile' => [...$published['profile'], 'accelerator' => 'invalid']])];
        foreach ($cases as $json) {
            file_put_contents($this->path, $json);
            self::assertNull($store->read());
        }
    }
}
