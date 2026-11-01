<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Infrastructure;

use App\Auth\Application\DTO\CurrentUserAuthority;
use App\Auth\Application\Port\CurrentUserAuthorityReader;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\LibraryReadScopeProvider;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class LibraryReadScopeProviderTest extends TestCase
{
    #[DataProvider('unavailableAccounts')]
    public function testUnavailableAccountHasNoScope(?CurrentUserAuthority $authority): void
    {
        $owner = new Uuid();
        $reader = $this->createMock(CurrentUserAuthorityReader::class);
        $reader->expects(self::once())->method('read')->with($owner)->willReturn($authority);
        $access = $this->createMock(LibraryAccessPortInterface::class);
        $access->expects(self::never())->method('getUserLibraryIds');
        $security = $this->createMock(Security::class);
        $security->expects(self::never())->method('getUser');
        $scope = new LibraryReadScopeProvider($security, $access, $reader)->forUser($owner);
        self::assertFalse($scope->isUnrestricted());
        self::assertSame([], $scope->getLibraryIds());
    }

    /** @return iterable<string, array{?CurrentUserAuthority}> */
    public static function unavailableAccounts(): iterable
    {
        yield 'missing' => [null];
        yield 'disabled' => [new CurrentUserAuthority(['ROLE_USER'], true)];
        yield 'disabled administrator' => [new CurrentUserAuthority(['ROLE_ADMIN'], true)];
    }

    #[DataProvider('administratorRoles')]
    public function testAdministratorGetsUnrestrictedScope(string $role): void
    {
        $reader = $this->createStub(CurrentUserAuthorityReader::class);
        $reader->method('read')->willReturn(new CurrentUserAuthority([$role], false));
        $access = $this->createMock(LibraryAccessPortInterface::class);
        $access->expects(self::never())->method('getUserLibraryIds');
        self::assertTrue(new LibraryReadScopeProvider($this->createStub(Security::class), $access, $reader)->forUser(new Uuid())->isUnrestricted());
    }

    /** @return iterable<int, array{string}> */
    public static function administratorRoles(): iterable
    {
        yield ['ROLE_ADMIN'];
        yield ['ROLE_SUPER_ADMIN'];
    }

    public function testExplicitAccountMembershipIsReadAgainAfterRevocation(): void
    {
        $owner = new Uuid();
        $library = new Uuid();
        $reader = $this->createMock(CurrentUserAuthorityReader::class);
        $reader->expects(self::exactly(2))->method('read')->with($owner)->willReturn(new CurrentUserAuthority(['ROLE_USER'], false));
        $access = $this->createMock(LibraryAccessPortInterface::class);
        $access->expects(self::exactly(2))->method('getUserLibraryIds')->with($owner)->willReturnOnConsecutiveCalls([$library->toString()], []);
        $provider = new LibraryReadScopeProvider($this->createStub(Security::class), $access, $reader);
        self::assertTrue($provider->forUser($owner)->allows($library));
        self::assertFalse($provider->forUser($owner)->allows($library));
    }
}
