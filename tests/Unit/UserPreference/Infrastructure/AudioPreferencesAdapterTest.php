<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserPreference\Infrastructure;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Port\PreferenceWriterPortInterface;
use App\UserPreference\Application\Exception\PreferenceVersionConflict;
use App\UserPreference\Domain\Model\AudioPreferences;
use App\UserPreference\Domain\Model\AudioPreferencesState;
use App\UserPreference\Domain\Model\PreferenceHistory;
use App\UserPreference\Domain\Model\PreferenceHistoryState;
use App\UserPreference\Domain\Repository\AudioPreferencesRepositoryInterface;
use App\UserPreference\Domain\Repository\PreferenceHistoryRepositoryInterface;
use App\UserPreference\Infrastructure\AudioPreferencesAdapter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AudioPreferencesAdapterTest extends TestCase
{
    public function testGetForUserReturnsNullWhenNoPreferences(): void
    {
        $userId = Uuid::generate();
        $historyStub = $this->createStub(PreferenceHistoryRepositoryInterface::class);
        $repository = $this->createMock(AudioPreferencesRepositoryInterface::class);
        $adapter = new AudioPreferencesAdapter($repository, $historyStub, $this->createStub(PreferenceWriterPortInterface::class));

        $repository
            ->expects($this->once())
            ->method('findByUserId')
            ->with($userId)
            ->willReturn(null);

        $result = $adapter->getForUser($userId);

        $this->assertNull($result);
    }

    public function testGetForUserReturnsPayloadWhenExists(): void
    {
        $userId = Uuid::generate();
        $payload = ['volume' => 75, 'eq_preset' => 'bass_boost'];
        $historyStub = $this->createStub(PreferenceHistoryRepositoryInterface::class);
        $repository = $this->createMock(AudioPreferencesRepositoryInterface::class);
        $adapter = new AudioPreferencesAdapter($repository, $historyStub, $this->createStub(PreferenceWriterPortInterface::class));

        $model = AudioPreferences::reconstitute(new AudioPreferencesState(
            id: Uuid::generate(),
            userId: $userId,
            payload: $payload,
            version: 1,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));

        $repository
            ->expects($this->once())
            ->method('findByUserId')
            ->with($userId)
            ->willReturn($model);

        $result = $adapter->getForUser($userId);

        $this->assertSame($payload, $result);
    }

    public function testSnapshotReturnsPayloadAndVersionFromOneRepositoryRead(): void
    {
        $userId = Uuid::generate();
        $payload = ['volume' => 75];
        $repository = $this->createMock(AudioPreferencesRepositoryInterface::class);
        $repository->expects($this->once())->method('findByUserId')->with($userId)
            ->willReturn(AudioPreferences::create($userId, $payload, 7));
        $adapter = new AudioPreferencesAdapter(
            $repository,
            $this->createStub(PreferenceHistoryRepositoryInterface::class),
            $this->createStub(PreferenceWriterPortInterface::class),
        );

        self::assertSame(['payload' => $payload, 'version' => 7], $adapter->getSnapshotForUser($userId));
    }

    public function testSaveForUserDelegatesExpectedVersionToAtomicWriter(): void
    {
        $userId = Uuid::generate();
        $payload = ['volume' => 80];
        $repository = $this->createMock(AudioPreferencesRepositoryInterface::class);
        $history = $this->createMock(PreferenceHistoryRepositoryInterface::class);
        $writer = $this->createMock(PreferenceWriterPortInterface::class);
        $repository->expects($this->never())->method('save');
        $history->expects($this->never())->method('save');
        $writer->expects($this->once())->method('saveForUser')->with('audio', $userId, $payload, 2)->willReturn(3);

        self::assertSame(3, (new AudioPreferencesAdapter($repository, $history, $writer))->saveForUser($userId, $payload, 2));
    }

    public function testSaveForUserPropagatesVersionConflict(): void
    {
        $writer = $this->createStub(PreferenceWriterPortInterface::class);
        $writer->method('saveForUser')->willThrowException(new PreferenceVersionConflict(5));
        $adapter = new AudioPreferencesAdapter(
            $this->createStub(AudioPreferencesRepositoryInterface::class),
            $this->createStub(PreferenceHistoryRepositoryInterface::class),
            $writer,
        );
        $this->expectException(PreferenceVersionConflict::class);
        $adapter->saveForUser(Uuid::generate(), [], 1);
    }

    public function testGetVersionReturnsNullWhenNoPreferences(): void
    {
        $userId = Uuid::generate();
        $historyStub = $this->createStub(PreferenceHistoryRepositoryInterface::class);
        $repository = $this->createMock(AudioPreferencesRepositoryInterface::class);
        $adapter = new AudioPreferencesAdapter($repository, $historyStub, $this->createStub(PreferenceWriterPortInterface::class));

        $repository
            ->expects($this->once())
            ->method('findByUserId')
            ->with($userId)
            ->willReturn(null);

        $result = $adapter->getVersion($userId);

        $this->assertNull($result);
    }

    public function testGetVersionReturnsCurrentVersion(): void
    {
        $userId = Uuid::generate();
        $historyStub = $this->createStub(PreferenceHistoryRepositoryInterface::class);
        $repository = $this->createMock(AudioPreferencesRepositoryInterface::class);
        $adapter = new AudioPreferencesAdapter($repository, $historyStub, $this->createStub(PreferenceWriterPortInterface::class));

        $model = AudioPreferences::reconstitute(new AudioPreferencesState(
            id: Uuid::generate(),
            userId: $userId,
            payload: [],
            version: 5,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));

        $repository
            ->expects($this->once())
            ->method('findByUserId')
            ->with($userId)
            ->willReturn($model);

        $result = $adapter->getVersion($userId);

        $this->assertSame(5, $result);
    }

    public function testGetHistoryReturnsFormattedEntries(): void
    {
        $userId = Uuid::generate();
        $repositoryStub = $this->createStub(AudioPreferencesRepositoryInterface::class);
        $historyRepository = $this->createMock(PreferenceHistoryRepositoryInterface::class);
        $adapter = new AudioPreferencesAdapter($repositoryStub, $historyRepository, $this->createStub(PreferenceWriterPortInterface::class));

        $entry1 = PreferenceHistory::reconstitute(new PreferenceHistoryState(
            id: Uuid::generate(),
            userId: $userId,
            preferenceType: 'audio',
            version: 2,
            payload: ['volume' => 80],
            createdAt: new DateTimeImmutable(),
        ));

        $entry2 = PreferenceHistory::reconstitute(new PreferenceHistoryState(
            id: Uuid::generate(),
            userId: $userId,
            preferenceType: 'audio',
            version: 1,
            payload: ['volume' => 50],
            createdAt: new DateTimeImmutable(),
        ));

        $historyRepository
            ->expects($this->once())
            ->method('findByUserAndType')
            ->with($userId, 'audio', 20)
            ->willReturn([$entry1, $entry2]);

        $result = $adapter->getHistory($userId);

        $this->assertCount(2, $result);
        $this->assertSame(2, $result[0]['version']);
        $this->assertSame(['volume' => 80], $result[0]['payload']);
        $this->assertSame(1, $result[1]['version']);
        $this->assertSame(['volume' => 50], $result[1]['payload']);
    }

    public function testRollbackToRestoresPreviousVersion(): void
    {
        $userId = Uuid::generate();
        $oldPayload = ['volume' => 30];
        $repository = $this->createMock(AudioPreferencesRepositoryInterface::class);
        $historyRepository = $this->createMock(PreferenceHistoryRepositoryInterface::class);
        $writer = $this->createMock(PreferenceWriterPortInterface::class);
        $adapter = new AudioPreferencesAdapter($repository, $historyRepository, $writer);

        $historyEntry = PreferenceHistory::reconstitute(new PreferenceHistoryState(
            id: Uuid::generate(),
            userId: $userId,
            preferenceType: 'audio',
            version: 1,
            payload: $oldPayload,
            createdAt: new DateTimeImmutable(),
        ));

        $historyRepository
            ->expects($this->once())
            ->method('findByUserAndTypeAndVersion')
            ->with($userId, 'audio', 1)
            ->willReturn($historyEntry);

        $repository
            ->expects($this->once())
            ->method('findByUserId')
            ->with($userId)
            ->willReturn(null);

        $writer->expects($this->once())->method('saveForUser')->with('audio', $userId, $oldPayload, 0)->willReturn(1);

        $result = $adapter->rollbackTo($userId, 1);

        $this->assertSame(['payload' => $oldPayload, 'version' => 1], $result);
    }

    public function testRollbackToThrowsWhenVersionNotFound(): void
    {
        $userId = Uuid::generate();
        $repositoryStub = $this->createStub(AudioPreferencesRepositoryInterface::class);
        $historyRepository = $this->createMock(PreferenceHistoryRepositoryInterface::class);
        $adapter = new AudioPreferencesAdapter($repositoryStub, $historyRepository, $this->createStub(PreferenceWriterPortInterface::class));

        $historyRepository
            ->expects($this->once())
            ->method('findByUserAndTypeAndVersion')
            ->with($userId, 'audio', 99)
            ->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No history entry found for version 99.');

        $adapter->rollbackTo($userId, 99);
    }
}
