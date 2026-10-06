<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\Passkey;

use App\Auth\Application\Command\Passkey\AuthenticatePasskeyCommand;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Application\DTO\VerifiedPasskeyLogin;
use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopNonceManager;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Auth\Infrastructure\Security\OAuth\DpopTokenRequestVerifier;
use App\Auth\Infrastructure\Security\Passkey\DpopProofRejectedException;
use App\Auth\Infrastructure\Security\Passkey\PasskeyAuthenticator;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class PasskeyAuthenticatorResolutionTest extends TestCase
{
    private const OWNER = '01900000-0000-7000-8000-000000000001';

    private SignedDpopProof $key;

    protected function setUp(): void
    {
        $this->key = new SignedDpopProof();
    }

    public function testSuccessfulPassportActuallyResolvesSecurityUser(): void
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $id = Uuid::fromString(self::OWNER);
        $now = new DateTimeImmutable();
        $user = User::reconstitute(new UserState(
            id: $id, publicId: new PublicId(), name: 'Owner', email: 'owner@baander.app',
            password: 'stored-password-hash', totpSecret: null, createdAt: $now, updatedAt: $now,
            roles: ['ROLE_USER', 'ROLE_ADMIN'],
        ));
        $repository->expects($this->once())->method('findByUuid')->with($id)->willReturn($user);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(
            static function (object $message): Envelope {
                self::assertInstanceOf(AuthenticatePasskeyCommand::class, $message);
                return new Envelope($message, [new HandledStamp(self::OWNER, 'handler')]);
            },
        );
        $authenticator = new PasskeyAuthenticator($bus, $repository, new NullLogger(), new JsonEncoder(), $this->verifier());
        $request = $this->request();
        $resolved = $authenticator->authenticate($request)->getUser();
        $login = $request->attributes->get(VerifiedPasskeyLogin::class);
        self::assertInstanceOf(VerifiedPasskeyLogin::class, $login);
        self::assertSame(self::OWNER, $login->userId->toString());
        self::assertSame($this->key->thumbprint(), $login->dpopJkt);
        self::assertNotSame('', $login->nextNonce);
        self::assertInstanceOf(SecurityUser::class, $resolved);
        self::assertSame(self::OWNER, $resolved->getId());
        self::assertSame('owner@baander.app', $resolved->getUserIdentifier());
        self::assertSame('stored-password-hash', $resolved->getPassword());
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $resolved->getRoles());
    }

    public function testMissingUserFailsWhenPassportIsResolved(): void
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->expects($this->once())->method('findByUuid')->willReturn(null);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(static fn (object $message): Envelope =>
            new Envelope($message, [new HandledStamp(self::OWNER, 'handler')]));
        $authenticator = new PasskeyAuthenticator($bus, $repository, new NullLogger(), new JsonEncoder(), $this->verifier());
        $this->expectException(BadCredentialsException::class);
        $authenticator->authenticate($this->request())->getUser();
    }

    public function testInvalidHandlerResultsBecomeGenericAuthenticationFailures(): void
    {
        foreach ([null, 17, 'not-a-uuid'] as $result) {
            $repository = $this->createMock(UserRepositoryInterface::class);
            $repository->expects($this->never())->method('findByUuid');
            $bus = $this->createMock(MessageBusInterface::class);
            $bus->expects($this->once())->method('dispatch')->willReturnCallback(static fn (object $message): Envelope =>
                new Envelope($message, [new HandledStamp($result, 'handler')]));
            $authenticator = new PasskeyAuthenticator($bus, $repository, new NullLogger(), new JsonEncoder(), $this->verifier());
            $this->assertGenericFailure($authenticator, $this->request());
        }
    }

    public function testMalformedRequestFailsBeforeDispatch(): void
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->expects($this->never())->method('findByUuid');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $authenticator = new PasskeyAuthenticator($bus, $repository, new NullLogger(), new JsonEncoder(), $this->verifier());
        foreach (['{', 'null', '17', '{"challengeKey":[],"response":[]}',
            '{"challengeKey":"challenge","response":"invalid"}',
            '{"challengeKey":"challenge","response":[],"userId":17}'] as $json) {
            $this->assertGenericFailure($authenticator, $this->request($json));
        }
    }

    public function testVerificationExceptionProducesGenericUnauthorizedResponse(): void
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->expects($this->never())->method('findByUuid');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willThrowException(new \RuntimeException('private-verifier-details'));
        $authenticator = new PasskeyAuthenticator($bus, $repository, new NullLogger(), new JsonEncoder(), $this->verifier());
        $this->assertGenericFailure($authenticator, $this->request());
    }

    public function testDisabledUserCannotSignIn(): void
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $now = new DateTimeImmutable();
        $user = User::reconstitute(new UserState(
            id: Uuid::fromString(self::OWNER), publicId: new PublicId(), name: 'Owner', email: 'owner@baander.app',
            password: 'stored-password-hash', totpSecret: null, createdAt: $now, updatedAt: $now,
            roles: ['ROLE_USER'], disabled: true,
        ));
        $repository->expects($this->once())->method('findByUuid')->willReturn($user);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(static fn (object $message): Envelope =>
            new Envelope($message, [new HandledStamp(self::OWNER, 'handler')]));
        $authenticator = new PasskeyAuthenticator($bus, $repository, new NullLogger(), new JsonEncoder(), $this->verifier());

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('This account has been disabled.');
        $authenticator->authenticate($this->request())->getUser();
    }

    public function testMissingOrNonceLessProofStopsBeforeTheCeremony(): void
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->expects($this->never())->method('findByUuid');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $authenticator = new PasskeyAuthenticator($bus, $repository, new NullLogger(), new JsonEncoder(), $this->verifier());

        $missing = $this->request(proof: false);
        $nonceLess = $this->request(proof: false);
        $nonceLess->headers->set('DPoP', $this->key->create('POST', 'https://baander.app/api/auth/login/passkey', 'no-access-token'));
        foreach ([[$missing, null], [$nonceLess, 'use_dpop_nonce']] as [$request, $error]) {
            try {
                $authenticator->authenticate($request);
                self::fail('Authentication must stop at the proof.');
            } catch (DpopProofRejectedException $rejected) {
                $response = $authenticator->onAuthenticationFailure($request, $rejected);
                self::assertSame(400, $response->getStatusCode());
                $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($error, $error === null ? null : $body['error']);
                self::assertFalse($request->attributes->has(VerifiedPasskeyLogin::class));
            }
        }
    }

    private function assertGenericFailure(PasskeyAuthenticator $authenticator, Request $request): void
    {
        try {
            $authenticator->authenticate($request);
            self::fail('Authentication must fail.');
        } catch (BadCredentialsException $error) {
            self::assertSame('Invalid credentials.', $error->getMessage());
            $response = $authenticator->onAuthenticationFailure($request, $error);
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('{"error":{"message":"Invalid credentials.","code":"AUTH_INVALID_CREDENTIALS"}}', $response->getContent());
        }
    }

    private function request(string $json = '{"challengeKey":"challenge","response":{"rawId":"Y3JlZA"}}', bool $proof = true): Request
    {
        $request = Request::create('https://baander.app/api/auth/login/passkey', 'POST', content: $json);
        if ($proof) {
            $request->headers->set('DPoP', $this->key->createWithNonce('POST', 'https://baander.app/api/auth/login/passkey', 'issued-nonce'));
        }

        return $request;
    }

    /** Real proof validation; the nonce store accepts every nonce. */
    private function verifier(): DpopTokenRequestVerifier
    {
        $redis = $this->createStub(RedisClientFactory::class);
        $redis->method('borrow')->willReturn(true);

        return new DpopTokenRequestVerifier(
            new DpopProofValidator($this->createStub(DpopJtiCacheInterface::class)),
            new DpopNonceManager($redis),
        );
    }
}
