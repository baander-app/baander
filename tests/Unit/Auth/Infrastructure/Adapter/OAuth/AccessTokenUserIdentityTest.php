<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Adapter\OAuth;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface as DomainAccessTokens;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface as DomainClients;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Adapter\OAuth\AccessTokenRepository;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use League\OAuth2\Server\Exception\OAuthServerException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class AccessTokenUserIdentityTest extends TestCase
{
    public function testMissingExplicitUserCannotBecomeClientIdentity(): void
    {
        $id = Uuid::generate();
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('findByUuid')->with(self::equalTo($id))->willReturn(null);
        $repository = $this->repository($users);

        try {
            $repository->getNewToken($this->client(), [], $id->toString());
            self::fail('A missing user must not produce a client token.');
        } catch (OAuthServerException $error) {
            self::assertSame('access_denied', $error->getErrorType());
            self::assertSame('User account is unavailable.', $error->getHint());
        }
    }

    public function testDisabledExplicitUserCannotBecomeClientIdentity(): void
    {
        $user = User::register(Email::fromString('disabled@baander.app'), 'hashed', 'Disabled user');
        $user->disable();
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('findByUuid')->with(self::equalTo($user->getId()))->willReturn($user);
        $repository = $this->repository($users);

        try {
            $repository->getNewToken($this->client(), [], $user->getId()->toString());
            self::fail('A disabled user must not produce a client token.');
        } catch (OAuthServerException $error) {
            self::assertSame('access_denied', $error->getErrorType());
            self::assertSame('User account is disabled.', $error->getHint());
        }
    }

    public function testClientCredentialIssuanceWithNoUserDoesNotLookUpAUser(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::never())->method('findByUuid');
        $client = $this->client();
        $token = $this->repository($users)->getNewToken($client, [], null);

        self::assertNull($token->getUser());
        self::assertNull($token->getUserIdentifier());
        self::assertSame($client, $token->getClient());
        self::assertNotSame('', $token->getIdentifier());
    }

    private function repository(UserRepositoryInterface $users): AccessTokenRepository
    {
        $tokens = $this->createMock(DomainAccessTokens::class);
        $tokens->expects(self::never())->method('save');
        return new AccessTokenRepository($tokens, $this->createStub(DomainClients::class), $users, new RequestStack(), 'https://baander.app');
    }

    private function client(): ClientEntity
    {
        return new ClientEntity(new PublicId(), 'Identity test client', '["https://baander.app/callback"]');
    }
}
