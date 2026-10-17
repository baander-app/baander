<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Infrastructure\FFmpeg\EncoderProfileFingerprintAdapter;
use App\Transcode\Infrastructure\FFmpeg\WorkerEncoderCapabilitiesSnapshotStore;
use PHPUnit\Framework\TestCase;

final class EncoderProfileFingerprintAdapterTest extends TestCase
{
    public function testFingerprintPreservesThePersistedProfileName(): void
    {
        $directory = sys_get_temp_dir() . '/baander-fingerprint-' . bin2hex(random_bytes(8));
        $path = $directory . '/capabilities.json';
        $store = new WorkerEncoderCapabilitiesSnapshotStore($path);
        try {
            $store->publish(EncoderProfile::software('libx265'), 1.0, 'worker-baander');
            self::assertSame('none/libx265', (new EncoderProfileFingerprintAdapter($store))->getName());
        } finally {
            unlink($path);
            rmdir($directory);
        }
    }

    public function testUnavailableSnapshotNeverFallsBackToLocalHardwareProbing(): void
    {
        $store = new WorkerEncoderCapabilitiesSnapshotStore(sys_get_temp_dir() . '/absent-baander-' . bin2hex(random_bytes(8)));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Worker encoder capabilities are unavailable');
        (new EncoderProfileFingerprintAdapter($store))->getName();
    }
}
