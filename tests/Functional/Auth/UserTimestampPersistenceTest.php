<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;

final class UserTimestampPersistenceTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function timestamps(): iterable
    {
        yield 'UTC fractions' => ['2026-04-12T10:20:30.123456+00:00', '2026-04-12T10:21:31.654321+00:00'];
        yield 'daylight saving offset boundary' => ['2026-10-25T02:59:58.123456+02:00', '2026-10-25T02:59:59.654321+02:00'];
    }

    #[DataProvider('timestamps')]
    public function testCreationUpdatesAndUnchangedSavesPreserveAggregateTimestamps(string $created, string $updated): void
    {
        $user = User::reconstitute(new UserState(
            id: Uuid::generate(),
            publicId: new PublicId(),
            name: 'Timestamp owner',
            email: 'timestamps@baander.app',
            password: 'unused-test-password',
            totpSecret: null,
            createdAt: new DateTimeImmutable($created),
            updatedAt: new DateTimeImmutable($updated),
        ));

        $this->userRepository->save($user);
        $stored = $this->reload($user);
        $this->assertTimestamps($user, $stored);

        $this->userRepository->save($stored);
        $unchanged = $this->reload($stored);
        $this->assertTimestamps($stored, $unchanged);

        $unchanged->updateName('Updated timestamp owner');
        $unchanged->getState()->updatedAt = new DateTimeImmutable('2026-10-25T02:01:00.987654+01:00');
        $this->userRepository->save($unchanged);
        $changed = $this->reload($unchanged);
        self::assertSame('Updated timestamp owner', $changed->getName());
        $this->assertTimestamps($unchanged, $changed);
    }

    private function reload(User $user): User
    {
        $this->entityManager->clear();
        $stored = $this->userRepository->findByUuid($user->getId());
        self::assertNotNull($stored);

        return $stored;
    }

    private function assertTimestamps(User $expected, User $actual): void
    {
        $utc = new DateTimeZone('UTC');
        self::assertSame($expected->getCreatedAt()->setTimezone($utc)->format('Y-m-d H:i:s.uP'), $actual->getCreatedAt()->format('Y-m-d H:i:s.uP'));
        self::assertSame($expected->getUpdatedAt()->setTimezone($utc)->format('Y-m-d H:i:s.uP'), $actual->getUpdatedAt()->format('Y-m-d H:i:s.uP'));
    }
}
