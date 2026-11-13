<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Infrastructure\Worker\NativeExecutionDaemon;
use App\Shared\Infrastructure\Worker\NativeExecutionProtocol;
use PHPUnit\Framework\TestCase;

final class NativeExecutionProfileTest extends TestCase
{
    public function testRemuxHandshakeReportsItsEnforcedLimits(): void
    {
        $reply = $this->handshake();
        self::assertTrue($reply['ok']);
        self::assertTrue($reply['ready']);
        self::assertSame(['commands' => 5, 'results' => 12, 'clients' => 4], $reply['limits']);
    }

    public function testContradictoryLimitsFailBeforeClientAdmission(): void
    {
        $reply = $this->handshake(['limits' => ['commands' => 8, 'results' => 32, 'clients' => 64]]);
        self::assertFalse($reply['ok']);
        self::assertStringContainsString('authority mismatch', $reply['error']);
    }

    public function testPeerWithOtherDeploymentSettingsIsRefused(): void
    {
        $reply = $this->handshake(['deploymentProfile' => new DeploymentProfile('remux', 3, 4, 1, sharedCopySlots: 2)->toJson()]);
        self::assertFalse($reply['ok']);
        self::assertStringContainsString('authority mismatch', $reply['error']);
    }

    public function testDisabledEncoderSlotCannotHandshake(): void
    {
        $reply = $this->handshake(['clientId' => 'media-video-0']);
        self::assertFalse($reply['ok']);
        self::assertFalse($reply['uncertain']);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function handshake(array $overrides = []): array
    {
        $profile = new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2);
        $daemon = new NativeExecutionDaemon('baander.app:workers', str_repeat('a', 32), 1, $profile);
        $request = array_replace(['id' => str_repeat('b', 32), 'namespace' => 'baander.app:workers', 'bootId' => str_repeat('a', 32),
            'epoch' => 1, 'clientId' => 'media-copy-0', 'generation' => 1, 'operation' => 'handshake',
            'deploymentProfile' => $profile->toJson(), 'limits' => NativeExecutionProtocol::limits($profile)], $overrides);
        $response = (new \ReflectionMethod($daemon, 'reply'))->invoke($daemon, NativeExecutionProtocol::encode($request, NativeExecutionProtocol::REQUEST_BYTES));
        return NativeExecutionProtocol::decode($response, NativeExecutionProtocol::RESPONSE_BYTES);
    }
}
