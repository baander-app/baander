<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Infrastructure;

use App\Auth\Application\DTO\CurrentUserAuthority;
use App\Auth\Application\Port\CurrentUserAuthorityReader;
use App\Auth\Infrastructure\Security\SecurityUser;
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

    /** @return iterable<string, array{bool, bool}> whether the request's token carries the admin role, unrestricted */
    public static function administratorRequests(): iterable
    {
        yield 'a first-party token of the administrator' => [true, true];
        yield 'a delegated token of the administrator' => [false, false];
    }

    /**
     * An administrator acting through their own request reads with the authority its token
     * carries, so a token they delegated to another client reaches only their granted libraries.
     */
    #[DataProvider('administratorRequests')]
    public function testAnAdministratorsOwnRequestReadsWithItsTokensAuthority(bool $tokenIsAdmin, bool $unrestricted): void
    {
        $owner = new Uuid();
        $library = new Uuid();
        $reader = $this->createStub(CurrentUserAuthorityReader::class);
        $reader->method('read')->willReturn(new CurrentUserAuthority(['ROLE_USER', 'ROLE_ADMIN'], false));
        $access = $this->createStub(LibraryAccessPortInterface::class);
        $access->method('getUserLibraryIds')->willReturn([$library->toString()]);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($owner->toString(), 'admin@baander.app', 'hashed-pw'));
        $security->method('isGranted')->willReturn($tokenIsAdmin);

        $scope = new LibraryReadScopeProvider($security, $access, $reader)->forUser($owner);

        self::assertSame($unrestricted, $scope->isUnrestricted());
        self::assertTrue($scope->allows($library));
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
