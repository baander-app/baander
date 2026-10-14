<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\Service;

use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The resolver other contexts' commands use finds a user by email address or UUID, or by public id alone, and nobody else. */
final class UserLookupTest extends TestCase
{
    public function testResolvesAUserByEmailAddressOrUuidToTheirUuid(): void
    {
        $user = User::createByOperator(new Email('viewer@baander.app'), 'hashed', 'Viewer', ['ROLE_USER']);
        $lookup = new UserLookup($this->repositoryWith($user));

        self::assertTrue($lookup->userId('viewer@baander.app')->equals($user->getId()));
        self::assertTrue($lookup->userId($user->getId()->toString())->equals($user->getId()));
    }

    /** @return iterable<string, array{string}> */
    public static function unknownUsers(): iterable
    {
        yield 'an unknown email address' => ['nobody@baander.app'];
        yield 'an unknown UUID' => [Uuid::generate()->toString()];
        yield 'neither an email address nor a UUID' => ['not-a-user'];
    }

    #[DataProvider('unknownUsers')]
    public function testAUserNobodyHasIsNotFound(string $identifier): void
    {
        $lookup = new UserLookup($this->repositoryWith(
            User::createByOperator(new Email('viewer@baander.app'), 'hashed', 'Viewer', ['ROLE_USER']),
        ));

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage(sprintf('User "%s" not found.', $identifier));

        $lookup->userId($identifier);
    }

    public function testResolvesAUserByPublicIdToTheirUuid(): void
    {
        $user = User::createByOperator(new Email('viewer@baander.app'), 'hashed', 'Viewer', ['ROLE_USER']);
        $lookup = new UserLookup($this->repositoryWith($user));

        self::assertTrue($lookup->userIdByPublicId($user->getPublicId())->equals($user->getId()));
    }

    public function testAPublicIdNobodyHasIsNotFound(): void
    {
        $lookup = new UserLookup($this->repositoryWith(
            User::createByOperator(new Email('viewer@baander.app'), 'hashed', 'Viewer', ['ROLE_USER']),
        ));
        $publicId = new PublicId();

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage(sprintf('User "%s" not found.', $publicId));

        $lookup->userIdByPublicId($publicId);
    }

    private function repositoryWith(User $user): UserRepositoryInterface
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByEmail')->willReturnCallback(
            static fn (Email $email): ?User => $email->toString() === $user->getEmail() ? $user : null,
        );
        $users->method('findByUuid')->willReturnCallback(
            static fn (Uuid $uuid): ?User => $uuid->equals($user->getId()) ? $user : null,
        );
        $users->method('findByPublicId')->willReturnCallback(
            static fn (PublicId $publicId): ?User => $publicId->equals($user->getPublicId()) ? $user : null,
        );

        return $users;
    }
}
