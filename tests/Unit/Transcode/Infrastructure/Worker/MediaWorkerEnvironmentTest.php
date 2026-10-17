<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Worker;

use App\Transcode\Infrastructure\Worker\MediaWorkerEnvironment;
use PHPUnit\Framework\TestCase;

final class MediaWorkerEnvironmentTest extends TestCase
{
    public function testAcceptsCompleteInheritedIdentity(): void
    {
        $identity = MediaWorkerEnvironment::identity($this->environment());
        self::assertSame('media', $identity->workerId);
        self::assertSame(12, $identity->launchGeneration);
        self::assertSame(3, $identity->leaseEpoch);
    }

    public function testRejectsOtherWorkerAndNoncanonicalIntegers(): void
    {
        foreach (['BAANDER_WORKER_ID' => 'consumer', 'BAANDER_WORKER_GENERATION' => '01', 'BAANDER_WORKER_LEASE_EPOCH' => '99999999999999999999999999'] as $key => $value) {
            $environment = $this->environment();
            $environment[$key] = $value;
            try {
                MediaWorkerEnvironment::identity($environment);
                self::fail('Invalid inherited identity was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        return ['BAANDER_WORKER_NAMESPACE' => 'test.baander.app', 'BAANDER_WORKER_BOOT_ID' => str_repeat('a', 32), 'BAANDER_WORKER_ID' => 'media', 'BAANDER_WORKER_GENERATION' => '12', 'BAANDER_WORKER_LEASE_EPOCH' => '3'];
    }
}
