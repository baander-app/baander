<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeSessionSharePort;
use App\Transcode\Application\Service\TranscodeSessionShare;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use Symfony\Component\Clock\MockClock;

/** For tests of start paths whose user holds no other session, on a deployment with free video slots. */
trait FreeTranscodeSessionShare
{
    private function freeSessionShare(?TranscodeSessionSharePort $store = null, int $sessionsPerUser = 6): TranscodeSessionShare
    {
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnCallback(static fn (string $key): int => $key === TranscodeSettingDefinitions::SESSIONS_PER_USER ? $sessionsPerUser : throw new \LogicException($key));
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());

        return new TranscodeSessionShare(
            $store ?? $this->createStub(TranscodeSessionSharePort::class),
            $this->createStub(TranscodeExecutionOwnershipPort::class),
            $transaction,
            $settings,
            new DeploymentProfile(),
            new MockClock(),
        );
    }
}
