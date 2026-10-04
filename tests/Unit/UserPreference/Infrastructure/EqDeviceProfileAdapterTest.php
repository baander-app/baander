<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserPreference\Infrastructure;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Exception\EqDeviceProfileNotFound;
use App\UserPreference\Domain\Model\EqDeviceProfile;
use App\UserPreference\Domain\Repository\EqDeviceProfileRepositoryInterface;
use App\UserPreference\Infrastructure\EqDeviceProfileAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EqDeviceProfileAdapterTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function inaccessibleProfiles(): iterable
    {
        foreach (['getProfile', 'updateProfile', 'deleteProfile', 'activateProfile'] as $method) {
            yield $method . ' missing' => [$method, false];
            yield $method . ' foreign' => [$method, true];
        }
    }

    #[DataProvider('inaccessibleProfiles')]
    public function testInaccessibleProfileNeverMutatesRepositoryOrModel(string $method, bool $foreign): void
    {
        $userId = Uuid::generate();
        $profile = $foreign ? EqDeviceProfile::create(Uuid::generate(), 'Private profile', payload: ['gain' => 3]) : null;
        $profileId = $profile?->getId() ?? Uuid::generate();
        $repository = $this->createMock(EqDeviceProfileRepositoryInterface::class);
        $repository->expects($this->once())->method('findById')->with($profileId)->willReturn($profile);
        $repository->expects($this->never())->method('save');
        $repository->expects($this->never())->method('delete');
        $adapter = new EqDeviceProfileAdapter($repository);
        try {
            $this->operate($adapter, $method, $userId, $profileId);
            self::fail('An inaccessible profile must be rejected.');
        } catch (EqDeviceProfileNotFound $exception) {
            self::assertSame('EQ device profile not found.', $exception->getMessage());
            self::assertStringNotContainsString($profileId->toString(), $exception->getMessage());
        }
        if ($profile !== null) {
            self::assertSame('Private profile', $profile->getName());
            self::assertSame(['gain' => 3], $profile->getPayload());
            self::assertSame(1, $profile->getVersion());
        }
    }

    public function testOwnerCanReadUpdateAndActivateProfile(): void
    {
        $userId = Uuid::generate();
        $profile = EqDeviceProfile::create($userId, 'Owned profile');
        $repository = $this->createMock(EqDeviceProfileRepositoryInterface::class);
        $repository->expects($this->exactly(3))->method('findById')->with($profile->getId())->willReturn($profile);
        $repository->expects($this->once())->method('save')->with($profile);
        $repository->expects($this->never())->method('delete');
        $adapter = new EqDeviceProfileAdapter($repository);
        self::assertSame('Owned profile', $adapter->getProfile($userId, $profile->getId())['name']);
        $updated = $adapter->updateProfile($userId, $profile->getId(), 'Updated', null, null, ['gain' => 6], null);
        self::assertSame('Updated', $updated['name']);
        self::assertSame(['gain' => 6], $updated['payload']);
        self::assertSame(['activeProfileId' => $profile->getId()->toString()], $adapter->activateProfile($userId, $profile->getId()));
    }

    public function testOwnerCanDeleteRegularProfile(): void
    {
        $userId = Uuid::generate();
        $profile = EqDeviceProfile::create($userId, 'Owned profile');
        $repository = $this->createMock(EqDeviceProfileRepositoryInterface::class);
        $repository->expects($this->once())->method('findById')->with($profile->getId())->willReturn($profile);
        $repository->expects($this->once())->method('delete')->with($profile);
        $adapter = new EqDeviceProfileAdapter($repository);
        $adapter->deleteProfile($userId, $profile->getId());
    }

    public function testDefaultProfileProtectionRunsOnlyAfterOwnershipCheck(): void
    {
        $ownerId = Uuid::generate();
        $profile = EqDeviceProfile::create($ownerId, 'Default', isDefault: true);
        $repository = $this->createMock(EqDeviceProfileRepositoryInterface::class);
        $repository->expects($this->exactly(2))->method('findById')->with($profile->getId())->willReturn($profile);
        $repository->expects($this->never())->method('delete');
        $adapter = new EqDeviceProfileAdapter($repository);
        try {
            $adapter->deleteProfile(Uuid::generate(), $profile->getId());
            self::fail('Foreign default profile must remain inaccessible.');
        } catch (EqDeviceProfileNotFound) {
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete the default profile.');
        $adapter->deleteProfile($ownerId, $profile->getId());
    }

    private function operate(EqDeviceProfileAdapter $adapter, string $method, Uuid $userId, Uuid $profileId): void
    {
        if ($method === 'updateProfile') {
            $adapter->updateProfile($userId, $profileId, 'Hacked', null, null, ['gain' => 12], null);
        } else {
            $adapter->{$method}($userId, $profileId);
        }
    }
}
