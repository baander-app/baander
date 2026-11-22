<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Application\Http\AccessTokenAttributes;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Tests\Functional\Security\TestAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** The functional-test authenticator models first-party and delegated tokens as the API authenticator sets them. */
final class TestAuthenticatorTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        $this->admin = User::createByOperator(new Email('admin@baander.app'), 'hashed-pw', 'Admin', ['ROLE_USER', 'ROLE_ADMIN']);
    }

    public function testWithoutTheScopesHeaderTheRequestIsFirstPartyWithTheUsersRoles(): void
    {
        $request = $this->request();

        $user = $this->authenticator()->authenticate($request)->getUser();

        self::assertTrue($request->attributes->get(AccessTokenAttributes::FIRST_PARTY));
        self::assertSame([], $request->attributes->get(AccessTokenAttributes::SCOPES));
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $user->getRoles());
    }

    public function testTheScopesHeaderModelsADelegatedTokenWithoutAdminRoles(): void
    {
        $request = $this->request();
        $request->headers->set('X-Baander-Test-Client-Scopes', 'library playlist');

        $user = $this->authenticator()->authenticate($request)->getUser();

        self::assertFalse($request->attributes->get(AccessTokenAttributes::FIRST_PARTY));
        self::assertSame(['library', 'playlist'], $request->attributes->get(AccessTokenAttributes::SCOPES));
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testTheRequestCarriesWhetherTheUsersAddressIsVerified(): void
    {
        $registrant = User::register(new Email('registrant@baander.app'), 'hashed-pw', 'Registrant');
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturnOnConsecutiveCalls($this->admin, $registrant);
        $authenticator = new TestAuthenticator($users);
        $operatorCreated = $this->request();
        $registered = $this->request();

        $authenticator->authenticate($operatorCreated)->getUser();
        $authenticator->authenticate($registered)->getUser();

        self::assertTrue($operatorCreated->attributes->get(AccessTokenAttributes::EMAIL_VERIFIED));
        self::assertFalse($registered->attributes->get(AccessTokenAttributes::EMAIL_VERIFIED));
    }

    private function request(): Request
    {
        $request = Request::create('/api/auth/me');
        $request->headers->set('X-Baander-Test-User-Id', $this->admin->getId()->toString());

        return $request;
    }

    private function authenticator(): TestAuthenticator
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturn($this->admin);

        return new TestAuthenticator($users);
    }
}
