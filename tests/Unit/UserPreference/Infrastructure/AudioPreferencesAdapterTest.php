<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserPreference\Infrastructure;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Repository\AudioPreferencesRepositoryInterface;
use App\UserPreference\Domain\Repository\PreferenceHistoryRepositoryInterface;
use App\UserPreference\Infrastructure\AudioPreferencesAdapter;
use App\UserPreference\Infrastructure\Doctrine\Entity\AudioPreferencesEntity;
use App\UserPreference\Infrastructure\Doctrine\Entity\PreferenceHistoryEntity;
use PHPUnit\Framework\MockObject\MockObject;
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

        $entity = new AudioPreferencesEntity(Uuid::generate());
        $entity->setUserId($userId);
        $entity->setPayload($payload);

        $repository
            ->expects($this->once())
            ->method('findByUserId')
            ->with($userId)
            ->willReturn($entity);

        $result = $adapter->getForUser($userId);

        $this->assertSame($payload, $result);
    }

    public function testSaveForUserCreatesNewEntityWhenNoneExists(): void
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
            ->with($this->callback(function (AudioPreferencesEntity $entity) use ($userId, $payload): bool {
                return $entity->getUserId()->equals($userId)
                    && $entity->getPayload() === $payload
                    && $entity->getVersion() === 1;
            }));

        $historyRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PreferenceHistoryEntity $entry) use ($userId, $payload): bool {
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

        $entity = new AudioPreferencesEntity(Uuid::generate());
        $entity->setUserId($userId);
        $entity->setPayload($existingPayload);
        $entity->setVersion(2);

        $repository
            ->expects($this->once())
            ->method('findByUserId')
            ->with($userId)
            ->willReturn($entity);

        $repository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (AudioPreferencesEntity $entity) use ($newPayload): bool {
                return $entity->getPayload() === $newPayload
                    && $entity->getVersion() === 3;
            }));

        $historyRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PreferenceHistoryEntity $entry): bool {
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

        $entity = new AudioPreferencesEntity(Uuid::generate());
        $entity->setUserId($userId);
        $entity->setPayload([]);
        $entity->setVersion(5);

        $repository
            ->expects($this->once())
            ->method('findByUserId')
            ->with($userId)
            ->willReturn($entity);

        $result = $adapter->getVersion($userId);

        $this->assertSame(5, $result);
    }

    public function testGetHistoryReturnsFormattedEntries(): void
    {
        $userId = Uuid::generate();
        $repositoryStub = $this->createStub(AudioPreferencesRepositoryInterface::class);
        $historyRepository = $this->createMock(PreferenceHistoryRepositoryInterface::class);
        $adapter = new AudioPreferencesAdapter($repositoryStub, $historyRepository);

        $entry1 = new PreferenceHistoryEntity(Uuid::generate());
        $entry1->setUserId($userId);
        $entry1->setPreferenceType('audio');
        $entry1->setVersion(2);
        $entry1->setPayload(['volume' => 80]);

        $entry2 = new PreferenceHistoryEntity(Uuid::generate());
        $entry2->setUserId($userId);
        $entry2->setPreferenceType('audio');
        $entry2->setVersion(1);
        $entry2->setPayload(['volume' => 50]);

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

        $historyEntry = new PreferenceHistoryEntity(Uuid::generate());
        $historyEntry->setUserId($userId);
        $historyEntry->setPreferenceType('audio');
        $historyEntry->setVersion(1);
        $historyEntry->setPayload($oldPayload);

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
