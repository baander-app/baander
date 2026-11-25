<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserPreference\Infrastructure;

use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Exception\EqDeviceProfileLimitReached;
use App\UserPreference\Application\Exception\EqDeviceProfileNotFound;
use App\UserPreference\Application\UserPreferenceLimits;
use App\UserPreference\Domain\Model\EqDeviceProfile;
use App\UserPreference\Domain\Repository\EqDeviceProfileRepositoryInterface;
use App\UserPreference\Infrastructure\EqDeviceProfileAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EqDeviceProfileAdapterTest extends TestCase
{
    private bool $inTransaction = false;

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
        $profileId = $profile?->getPublicId() ?? new PublicId();
        $repository = $this->createMock(EqDeviceProfileRepositoryInterface::class);
        $repository->expects($this->once())->method('findByPublicId')->with($profileId)->willReturn($profile);
        $repository->expects($this->never())->method('save');
        $repository->expects($this->never())->method('delete');
        $adapter = $this->adapter($repository);
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
        $repository->expects($this->exactly(3))->method('findByPublicId')->with($profile->getPublicId())->willReturn($profile);
        $repository->expects($this->once())->method('save')->with($profile);
        $repository->expects($this->never())->method('delete');
        $adapter = $this->adapter($repository);
        self::assertSame('Owned profile', $adapter->getProfile($userId, $profile->getPublicId())['name']);
        $updated = $adapter->updateProfile($userId, $profile->getPublicId(), 'Updated', null, null, ['gain' => 6], null);
        self::assertSame('Updated', $updated['name']);
        self::assertSame(['gain' => 6], $updated['payload']);
        self::assertSame(['activeProfileId' => $profile->getPublicId()->toString()], $adapter->activateProfile($userId, $profile->getPublicId()));
    }

    public function testOwnerCanDeleteRegularProfile(): void
    {
        $userId = Uuid::generate();
        $profile = EqDeviceProfile::create($userId, 'Owned profile');
        $repository = $this->createMock(EqDeviceProfileRepositoryInterface::class);
        $repository->expects($this->once())->method('findByPublicId')->with($profile->getPublicId())->willReturn($profile);
        $repository->expects($this->once())->method('delete')->with($profile);
        $adapter = $this->adapter($repository);
        $adapter->deleteProfile($userId, $profile->getPublicId());
    }

    public function testDefaultProfileProtectionRunsOnlyAfterOwnershipCheck(): void
    {
        $ownerId = Uuid::generate();
        $profile = EqDeviceProfile::create($ownerId, 'Default', isDefault: true);
        $repository = $this->createMock(EqDeviceProfileRepositoryInterface::class);
        $repository->expects($this->exactly(2))->method('findByPublicId')->with($profile->getPublicId())->willReturn($profile);
        $repository->expects($this->never())->method('delete');
        $adapter = $this->adapter($repository);
        try {
            $adapter->deleteProfile(Uuid::generate(), $profile->getPublicId());
            self::fail('Foreign default profile must remain inaccessible.');
        } catch (EqDeviceProfileNotFound) {
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete the default profile.');
        $adapter->deleteProfile($ownerId, $profile->getPublicId());
    }

    public function testClientAudioOutputTokenIsPreservedIndependentlyOfProfileIdentity(): void
    {
        $owner = new Uuid();
        $token = 'audio-output-group-abc';
        $repository = $this->createMock(EqDeviceProfileRepositoryInterface::class);
        $repository->method('findByUserId')->willReturn([]);
        $repository->expects(self::once())->method('save')->with(self::callback(
            static fn (EqDeviceProfile $profile): bool => $profile->getDeviceId() === $token,
        ));

        $result = $this->adapter($repository)->createProfile($owner, 'Headphones', 'headphones', $token, []);

        self::assertSame($token, $result['deviceId']);
        self::assertArrayNotHasKey('id', $result);
        self::assertSame($result['publicId'], PublicId::fromString($result['publicId'])->toString());
    }

    public function testCreationCountsTheProfilesUnderTheUsersLockAndRefusesOneOverTheCap(): void
    {
        $owner = new Uuid();
        $existing = array_map(
            static fn (int $order): EqDeviceProfile => EqDeviceProfile::create($owner, 'Profile ' . $order, sortOrder: $order),
            range(0, UserPreferenceLimits::EQ_PROFILES_PER_USER - 1),
        );
        $calls = [];
        $repository = $this->createMock(EqDeviceProfileRepositoryInterface::class);
        $repository->expects(self::once())->method('lockUserProfiles')->with($owner)->willReturnCallback(
            function () use (&$calls): void {
                self::assertTrue($this->inTransaction, 'The lock must be taken inside the creating transaction.');
                $calls[] = 'lock';
            },
        );
        $repository->expects(self::once())->method('findByUserId')->with($owner)->willReturnCallback(
            static function () use (&$calls, $existing): array {
                $calls[] = 'read';

                return $existing;
            },
        );
        $repository->expects(self::never())->method('save');

        try {
            $this->adapter($repository)->createProfile($owner, 'One too many', 'custom', null, []);
            self::fail('A profile over the cap must be refused.');
        } catch (EqDeviceProfileLimitReached $refusal) {
            self::assertSame(['reason' => 'eq_profile_limit', 'limit' => 50], $refusal->details);
        }
        self::assertSame(['lock', 'read'], $calls);
    }

    public function testCreationBelowTheCapTakesTheNextSortOrder(): void
    {
        $owner = new Uuid();
        $existing = [
            EqDeviceProfile::create($owner, 'First', sortOrder: 4),
            EqDeviceProfile::create($owner, 'Second', sortOrder: 1),
        ];
        $repository = $this->createMock(EqDeviceProfileRepositoryInterface::class);
        $repository->method('findByUserId')->willReturn($existing);
        $repository->expects(self::once())->method('save')->with(self::callback(
            static fn (EqDeviceProfile $profile): bool => $profile->getSortOrder() === 5,
        ));

        self::assertSame(5, $this->adapter($repository)->createProfile($owner, 'Third', 'custom', null, [])['sortOrder']);
    }

    private function adapter(EqDeviceProfileRepositoryInterface $repository): EqDeviceProfileAdapter
    {
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->inTransaction = true;
            try {
                return $operation();
            } finally {
                $this->inTransaction = false;
            }
        });

        return new EqDeviceProfileAdapter($repository, $transaction);
    }

    private function operate(EqDeviceProfileAdapter $adapter, string $method, Uuid $userId, PublicId $profileId): void
    {
        if ($method === 'updateProfile') {
            $adapter->updateProfile($userId, $profileId, 'Hacked', null, null, ['gain' => 12], null);
        } else {
            $adapter->{$method}($userId, $profileId);
        }
    }
}
