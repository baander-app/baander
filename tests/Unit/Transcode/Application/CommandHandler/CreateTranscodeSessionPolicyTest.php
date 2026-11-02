<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Application\CommandHandler;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\CommandHandler\CreateTranscodeSessionHandler;
use App\Transcode\Application\Exception\MediaEncodingProhibitedException;
use App\Transcode\Application\Port\EncodePresentationPort;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use PHPUnit\Framework\TestCase;

final class CreateTranscodeSessionPolicyTest extends TestCase
{
    public function testHouseholdRefusesFreshEncodingBeforeDurableAdmission(): void
    {
        $jobs = $this->createMock(TranscodeJobPortInterface::class);
        $jobs->expects(self::never())->method('getOrCreateJob');
        $sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $sessions->expects(self::never())->method('createSession');
        $ownership = $this->createMock(TranscodeExecutionOwnershipPort::class);
        $ownership->expects(self::never())->method('admit');
        $transaction = $this->createMock(TransactionPortInterface::class);
        $transaction->expects(self::never())->method('run');
        $storage = $this->createMock(TranscodeStoragePortInterface::class);
        $storage->expects(self::never())->method('resolveJobDirectory');
        $presentations = $this->createMock(EncodePresentationPort::class);
        $presentations->expects(self::never())->method('select');
        $handler = new CreateTranscodeSessionHandler(new MediaExecutionPolicy(new DeploymentProfile('household')), $jobs, $sessions, $storage, $ownership, $transaction, $this->createStub(SleeperInterface::class), $presentations);
        $this->expectException(MediaEncodingProhibitedException::class);
        $handler(new CreateTranscodeSessionCommand(Uuid::generate(), Uuid::generate(), QualityTier::p720(), AudioProfile::streamingStereo()));
    }
}
