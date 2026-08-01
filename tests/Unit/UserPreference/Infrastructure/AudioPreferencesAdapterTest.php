<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserPreference\Infrastructure;

use App\Shared\Domain\Model\Uuid;
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
        $adapter = new AudioPreferencesAdapter($repository, $historyStub);

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
        $adapter = new AudioPreferencesAdapter($repository, $historyStub);

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

    public function testSaveForUserCreatesNewModelWhenNoneExists(): void
    {
        $userId = Uuid::generate();
        $payload = ['volume' => 50];
        $repository = $this->createMock(AudioPreferencesRepositoryInterface::class);
        $historyRepository = $this->createMock(PreferenceHistoryRepositoryInterface::class);
        $adapter = new AudioPreferencesAdapter($repository, $historyRepository);

        $repository
            ->expects($this->once())
            ->method('findByUserId')
            ->with($userId)
            ->willReturn(null);

        $repository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (AudioPreferences $model) use ($userId, $payload): bool {
                return $model->getUserId()->equals($userId)
                    && $model->getPayload() === $payload
                    && $model->getVersion() === 1;
            }));

        $historyRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PreferenceHistory $entry) use ($userId, $payload): bool {
                return $entry->getUserId()->equals($userId)
                    && $entry->getPreferenceType() === 'audio'
                    && $entry->getVersion() === 1
                    && $entry->getPayload() === $payload;
            }));

        $newVersion = $adapter->saveForUser($userId, $payload, 0);

        $this->assertSame(1, $newVersion);
    }

    public function testSaveForUserIncrementsVersionOnUpdate(): void
    {
        $userId = Uuid::generate();
        $existingPayload = ['volume' => 50];
        $newPayload = ['volume' => 80];
        $repository = $this->createMock(AudioPreferencesRepositoryInterface::class);
        $historyRepository = $this->createMock(PreferenceHistoryRepositoryInterface::class);
        $adapter = new AudioPreferencesAdapter($repository, $historyRepository);

        $model = AudioPreferences::reconstitute(new AudioPreferencesState(
            id: Uuid::generate(),
            userId: $userId,
            payload: $existingPayload,
            version: 2,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));

        $repository
            ->expects($this->once())
            ->method('findByUserId')
            ->with($userId)
            ->willReturn($model);

        $repository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (AudioPreferences $model) use ($newPayload): bool {
                return $model->getPayload() === $newPayload
                    && $model->getVersion() === 3;
            }));

        $historyRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PreferenceHistory $entry): bool {
                return $entry->getVersion() === 3;
            }));

        $newVersion = $adapter->saveForUser($userId, $newPayload, 2);

        $this->assertSame(3, $newVersion);
    }

    public function testGetVersionReturnsNullWhenNoPreferences(): void
    {
        $userId = Uuid::generate();
        $historyStub = $this->createStub(PreferenceHistoryRepositoryInterface::class);
        $repository = $this->createMock(AudioPreferencesRepositoryInterface::class);
        $adapter = new AudioPreferencesAdapter($repository, $historyStub);

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
        $adapter = new AudioPreferencesAdapter($repository, $historyStub);

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
        $adapter = new AudioPreferencesAdapter($repositoryStub, $historyRepository);

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
        $adapter = new AudioPreferencesAdapter($repository, $historyRepository);

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

        $repository
            ->expects($this->once())
            ->method('save');

        $historyRepository
            ->expects($this->once())
            ->method('save');

        $result = $adapter->rollbackTo($userId, 1);

        $this->assertSame($oldPayload, $result);
    }

    public function testRollbackToThrowsWhenVersionNotFound(): void
    {
        $userId = Uuid::generate();
        $repositoryStub = $this->createStub(AudioPreferencesRepositoryInterface::class);
        $historyRepository = $this->createMock(PreferenceHistoryRepositoryInterface::class);
        $adapter = new AudioPreferencesAdapter($repositoryStub, $historyRepository);

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
