<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller\Passkey;

use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Model\Passkey\PasskeyState;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\Controller\Passkey\PasskeyController;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class PasskeyControllerTest extends TestCase
{
    private Security&Stub $security;
    private PasskeyRepositoryInterface&Stub $passkeyRepository;
    private PasskeyController $controller;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->passkeyRepository = $this->createStub(PasskeyRepositoryInterface::class);

        $this->controller = $this->createController();
    }

    private function createController(): PasskeyController
    {
        $ref = new \ReflectionClass(PasskeyController::class);
        $controller = $ref->newInstanceWithoutConstructor();

        $this->setPrivate($controller, 'security', $this->security);
        $this->setPrivate($controller, 'passkeyRepository', $this->passkeyRepository);
        return $controller;
    }

    public function testListReturnsPasskeysForAuthenticatedUser(): void
    {
        $this->passkeyRepository = $this->createMock(PasskeyRepositoryInterface::class);
        $this->controller = $this->createController();

        $userId = Uuid::v4();
        $user = new SecurityUser(
            id: $userId->toString(),
            email: 'user@baander.app',
            password: 'hashed-pw',
        );
        $this->security->method('getUser')->willReturn($user);

        $passkey1 = Passkey::create(Uuid::v4(), 'YubiKey', 'cred-1', ['publicKey' => 'key1'], 0);
        $passkey2 = Passkey::create(Uuid::v4(), 'iPhone', 'cred-2', ['publicKey' => 'key2'], 5);

        $this->passkeyRepository->expects($this->once())
            ->method('forUser')
            ->willReturn([$passkey1, $passkey2]);

        $response = $this->controller->list();

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertCount(2, $data['data']);
        $this->assertArrayHasKey('publicId', $data['data'][0]);
        $this->assertSame($passkey1->getPublicId()->toString(), $data['data'][0]['publicId']);
        $this->assertStringNotContainsString($passkey1->getId()->toString(), $response->getContent());
        $this->assertStringNotContainsString($passkey2->getId()->toString(), $response->getContent());
        $this->assertArrayHasKey('name', $data['data'][0]);
        $this->assertArrayHasKey('createdAt', $data['data'][0]);
        $this->assertArrayHasKey('lastUsedAt', $data['data'][0]);
    }

    public function testListReturnsEmptyArrayForUserWithNoPasskeys(): void
    {
        $userId = Uuid::v4();
        $user = new SecurityUser(
            id: $userId->toString(),
            email: 'user@baander.app',
            password: 'hashed-pw',
        );
        $this->security->method('getUser')->willReturn($user);

        $this->passkeyRepository->method('forUser')->willReturn([]);

        $response = $this->controller->list();

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame([], $data['data']);
    }

    public function testListReturns401ForUnauthenticatedUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $response = $this->controller->list();

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testListNeverIncludesCredentialIdOrData(): void
    {
        $userId = Uuid::v4();
        $user = new SecurityUser(
            id: $userId->toString(),
            email: 'user@baander.app',
            password: 'hashed-pw',
        );
        $this->security->method('getUser')->willReturn($user);

        $passkey = Passkey::create(Uuid::v4(), 'Test Key', 'secret-credential-id', ['sensitive' => 'data'], 0);

        $this->passkeyRepository->method('forUser')->willReturn([$passkey]);

        $response = $this->controller->list();
        $content = $response->getContent();

        $this->assertStringNotContainsString('secret-credential-id', $content);
        $this->assertStringNotContainsString('sensitive', $content);
        $this->assertStringNotContainsString('credentialId', $content);
    }

    public function testListOrdersByCreatedAtDescending(): void
    {
        $userId = Uuid::v4();
        $user = new SecurityUser(
            id: $userId->toString(),
            email: 'user@baander.app',
            password: 'hashed-pw',
        );
        $this->security->method('getUser')->willReturn($user);

        $older = self::makePasskey(Uuid::v4(), 'Older', 'c1', new DateTimeImmutable('2026-01-01'));
        $newer = self::makePasskey(Uuid::v4(), 'Newer', 'c2', new DateTimeImmutable('2026-05-01'));

        $this->passkeyRepository->method('forUser')->willReturn([$older, $newer]);

        $response = $this->controller->list();
        $data = json_decode($response->getContent(), true);

        $this->assertSame('Newer', $data['data'][0]['name']);
        $this->assertSame('Older', $data['data'][1]['name']);
    }

    public function testAuthenticationOptionsResolveOnlyPublicUserIdentity(): void
    {
        $owner = \App\Auth\Domain\Model\User::register(
            new \App\Shared\Domain\Model\Email('passkey@baander.app'), 'hash', 'Passkey user',
        );
        $users = $this->createMock(\App\Auth\Application\Port\UserPortInterface::class);
        $users->expects(self::once())->method('findByPublicId')
            ->with(self::callback(fn ($id) => $id->equals($owner->getPublicId())))
            ->willReturn($owner);
        $users->expects(self::never())->method('findByUuid');
        $repository = $this->createMock(PasskeyRepositoryInterface::class);
        $repository->expects(self::once())->method('forUser')
            ->with(self::callback(fn ($id) => $id->equals($owner->getId())))
            ->willReturn([]);
        $this->passkeyRepository = $repository;
        $this->controller = $this->createController();
        $this->setPrivate($this->controller, 'users', $users);
        $this->setPrivate($this->controller, 'passkeyService', $this->authenticationOptionsService());

        $response = $this->controller->authenticationOptions(
            new \App\Auth\Interface\Request\Passkey\WebAuthnOptionsRequest($owner->getPublicId()->toString()),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString($owner->getId()->toString(), $response->getContent());
    }

    public function testAuthenticationOptionsDoNotResolveInternalUserUuid(): void
    {
        $users = $this->createMock(\App\Auth\Application\Port\UserPortInterface::class);
        $users->expects(self::never())->method('findByPublicId');
        $users->expects(self::never())->method('findByUuid');
        $repository = $this->createMock(PasskeyRepositoryInterface::class);
        $repository->expects(self::never())->method('forUser');
        $this->passkeyRepository = $repository;
        $this->controller = $this->createController();
        $this->setPrivate($this->controller, 'users', $users);
        $this->setPrivate($this->controller, 'passkeyService', $this->authenticationOptionsService());

        $response = $this->controller->authenticationOptions(
            new \App\Auth\Interface\Request\Passkey\WebAuthnOptionsRequest(Uuid::v7()->toString()),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRegistrationOptionsEncodeThePublicUserHandle(): void
    {
        $owner = \App\Auth\Domain\Model\User::register(new \App\Shared\Domain\Model\Email('passkey@baander.app'), 'hash', 'Owner');
        $this->security->method('getUser')->willReturn(new SecurityUser(id: $owner->getId()->toString(), email: $owner->getEmail(), password: 'hash'));
        $users = $this->createStub(\App\Auth\Application\Port\UserPortInterface::class);
        $users->method('findByUuid')->willReturn($owner);
        $this->passkeyRepository->method('forUser')->willReturn([]);
        $this->setPrivate($this->controller, 'users', $users);
        $this->setPrivate($this->controller, 'passkeyService', $this->authenticationOptionsService());

        $response = $this->controller->registrationOptions();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($owner->getPublicId()->toString(), base64_decode(strtr($data['data']['options']['user']['id'], '-_', '+/'), true));
        self::assertStringNotContainsString($owner->getId()->toString(), $response->getContent());
    }

    /** @return iterable<string, array{string, int}> */
    public static function authenticationHints(): iterable
    {
        yield 'discoverable' => ['', 200];
        yield 'public hint' => ['public', 200];
        yield 'internal UUID hint' => ['uuid', 401];
        yield 'foreign public hint' => ['foreign', 401];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('authenticationHints')]
    public function testRealAssertionUsesPublicIdentityInHintAndResult(string $hint, int $status): void
    {
        $owner = \App\Auth\Domain\Model\User::register(new \App\Shared\Domain\Model\Email('passkey@baander.app'), 'hash', 'Owner');
        $credential = new \App\Tests\Fixtures\Auth\WebAuthnTestCredential($owner->getPublicId()->toString(), 'baander.app');
        $passkey = $credential->passkey();
        $service = $this->authenticationOptionsService();
        $options = $service->createAuthenticationOptions();
        $assertion = $credential->assert($options['options']['challenge'], 'https://baander.app');
        $users = $this->createStub(\App\Auth\Application\Port\UserPortInterface::class);
        $users->method('findByUuid')->willReturn($owner);
        $repository = $this->createMock(PasskeyRepositoryInterface::class);
        $repository->expects(self::once())->method('ofCredentialId')->with($passkey->getCredentialId())->willReturn($passkey);
        $repository->method('userIdForCredentialId')->willReturn($owner->getId());
        $repository->expects($status === 200 ? self::once() : self::never())->method('markUsed');
        $this->passkeyRepository = $repository;
        $this->controller = $this->createController();
        $this->controller->setTranslator(new \Symfony\Component\Translation\Translator('en'));
        $this->setPrivate($this->controller, 'users', $users);
        $this->setPrivate($this->controller, 'passkeyService', $service);
        $claimed = match ($hint) {
            'public' => $owner->getPublicId()->toString(),
            'uuid' => $owner->getId()->toString(),
            'foreign' => (new \App\Shared\Domain\Model\PublicId())->toString(),
            default => '',
        };

        $response = $this->controller->authenticate(new \App\Auth\Interface\Request\Passkey\VerifyPasskeyChallengeRequest($options['challengeKey'], $assertion, $claimed));
        self::assertSame($status, $response->getStatusCode());
        self::assertStringNotContainsString($owner->getId()->toString(), $response->getContent());
        self::assertSame($status === 200 ? 1 : 0, $passkey->getCounter());
        if ($status === 200) {
            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($owner->getPublicId()->toString(), $data['data']['userId']);
        }
    }

    private function authenticationOptionsService(): \App\Auth\Infrastructure\Security\Passkey\PasskeyService
    {
        return new \App\Auth\Infrastructure\Security\Passkey\PasskeyService(
            appDomain: 'baander.app', appName: 'Baander', timeout: 60000,
            authenticatorAttachment: '', userVerification: 'preferred', residentKey: 'preferred', attestation: 'none',
            counterChecker: $this->createStub(\Webauthn\Counter\CounterChecker::class),
            eventDispatcher: $this->createStub(\Psr\EventDispatcher\EventDispatcherInterface::class),
            logger: new \Psr\Log\NullLogger(),
            cache: new \Symfony\Component\Cache\Adapter\ArrayAdapter(),
            supportedAlgorithmIds: [-7, -257],
            jsonEncoder: new \Symfony\Component\Serializer\Encoder\JsonEncoder(),
        );
    }

    private static function makePasskey(Uuid $id, string $name, string $credentialId, DateTimeImmutable $createdAt): Passkey
    {
        $state = new PasskeyState(
            id: $id,
            publicId: new \App\Shared\Domain\Model\PublicId(),
            name: $name,
            credentialId: $credentialId,
            data: [],
            counter: 0,
            createdAt: $createdAt,
            updatedAt: $createdAt,
        );

        return Passkey::reconstitute($state);
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setValue($object, $value);
    }
}
