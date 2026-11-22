<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller\Passkey;

use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Port\StepUpAttemptThrottleInterface;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Application\Service\StepUpVerifier;
use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Model\Passkey\PasskeyState;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\Controller\Passkey\PasskeyController;
use App\Auth\Interface\Request\Passkey\PasskeyRegistrationOptionsRequest;
use App\Shared\Application\Http\PublicOrigins;
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
        $controller->setTranslator(new \Symfony\Component\Translation\Translator('en'));
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

    public function testRegistrationOptionsNeedAVerifiedAddressBeforeStepUp(): void
    {
        $owner = \App\Auth\Domain\Model\User::register(new \App\Shared\Domain\Model\Email('passkey@baander.app'), 'hash', 'Owner');
        $this->security->method('getUser')->willReturn(new SecurityUser(id: $owner->getId()->toString(), email: $owner->getEmail(), password: 'hash'));
        $users = $this->createStub(\App\Auth\Application\Port\UserPortInterface::class);
        $users->method('findByUuid')->willReturn($owner);
        $this->setPrivate($this->controller, 'users', $users);
        $this->setPrivate($this->controller, 'stepUp', $this->stepUp());

        try {
            $this->controller->registrationOptions(new PasskeyRegistrationOptionsRequest('not-the-password'));
            self::fail('Registration options must be refused for an unverified address.');
        } catch (\App\Auth\Application\Exception\EmailUnverifiedException $exception) {
            self::assertSame(['reason' => 'email_unverified'], $exception->details);
        }
    }

    public function testRegistrationOptionsEncodeThePublicUserHandle(): void
    {
        $owner = \App\Auth\Domain\Model\User::register(new \App\Shared\Domain\Model\Email('passkey@baander.app'), 'hash', 'Owner');
        $owner->verifyEmail();
        $this->security->method('getUser')->willReturn(new SecurityUser(id: $owner->getId()->toString(), email: $owner->getEmail(), password: 'hash'));
        $users = $this->createStub(\App\Auth\Application\Port\UserPortInterface::class);
        $users->method('findByUuid')->willReturn($owner);
        $this->passkeyRepository->method('forUser')->willReturn([]);
        $this->setPrivate($this->controller, 'users', $users);
        $this->setPrivate($this->controller, 'passkeyService', $this->passkeyService());
        $this->setPrivate($this->controller, 'stepUp', $this->stepUp());

        $response = $this->controller->registrationOptions(new PasskeyRegistrationOptionsRequest('current-password'));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($owner->getPublicId()->toString(), base64_decode(strtr($data['data']['options']['user']['id'], '-_', '+/'), true));
        self::assertStringNotContainsString($owner->getId()->toString(), $response->getContent());
    }

    public function testARegistrationCeremonyCompletesForTheUserTheChallengeWasIssuedTo(): void
    {
        $owner = \App\Auth\Domain\Model\User::register(new \App\Shared\Domain\Model\Email('passkey@baander.app'), 'hash', 'Owner');
        $owner->verifyEmail();
        $other = \App\Auth\Domain\Model\User::register(new \App\Shared\Domain\Model\Email('other@baander.app'), 'hash', 'Other');
        $signedIn = $owner;
        $this->security->method('getUser')->willReturnCallback(static function () use (&$signedIn): SecurityUser {
            return new SecurityUser(id: $signedIn->getId()->toString(), email: $signedIn->getEmail(), password: 'hash');
        });
        $users = $this->createStub(\App\Auth\Application\Port\UserPortInterface::class);
        $users->method('findByUuid')->willReturnCallback(static fn (Uuid $id): \App\Auth\Domain\Model\User => $id->equals($owner->getId()) ? $owner : $other);
        $this->passkeyRepository->method('forUser')->willReturn([]);
        $registered = [];
        $bus = $this->createStub(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$registered): \Symfony\Component\Messenger\Envelope {
            $registered[] = $message;

            return new \Symfony\Component\Messenger\Envelope($message);
        });
        $this->setPrivate($this->controller, 'users', $users);
        $this->setPrivate($this->controller, 'passkeyService', $this->passkeyService());
        $this->setPrivate($this->controller, 'stepUp', $this->stepUp());
        $this->setPrivate($this->controller, 'commandBus', $bus);
        $credential = new \App\Tests\Fixtures\Auth\WebAuthnTestCredential($owner->getPublicId()->toString(), 'baander.app');

        $options = json_decode($this->controller->registrationOptions(new PasskeyRegistrationOptionsRequest('current-password'))->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $attestation = self::asTheWebClientSendsIt($credential->attest($options['options']['challenge'], 'https://baander.app'));
        $signedIn = $other;
        $borrowed = $this->controller->register(new \App\Auth\Interface\Request\Passkey\RegisterPasskeyRequest($options['challengeKey'], $attestation, 'Borrowed'), new \Symfony\Component\HttpFoundation\Request());
        $signedIn = $owner;
        $completed = $this->controller->register(new \App\Auth\Interface\Request\Passkey\RegisterPasskeyRequest($options['challengeKey'], $attestation, 'Laptop'), new \Symfony\Component\HttpFoundation\Request());

        self::assertSame(400, $borrowed->getStatusCode(), (string) $borrowed->getContent());
        self::assertSame('invalid_challenge', json_decode((string) $borrowed->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']['details']['reason']);
        self::assertSame(201, $completed->getStatusCode(), (string) $completed->getContent());
        self::assertCount(1, $registered);
        self::assertInstanceOf(\App\Auth\Application\Command\Passkey\RegisterPasskeyCommand::class, $registered[0]);
        self::assertSame($owner->getId()->toString(), $registered[0]->getUserId());
    }

    private function passkeyService(): \App\Auth\Infrastructure\Security\Passkey\PasskeyService
    {
        return new \App\Auth\Infrastructure\Security\Passkey\PasskeyService(
            publicOrigins: new PublicOrigins('https://baander.app'), appName: 'Baander', timeout: 60000,
            authenticatorAttachment: '', userVerification: 'preferred', residentKey: 'preferred', attestation: 'none',
            counterChecker: $this->createStub(\Webauthn\Counter\CounterChecker::class),
            eventDispatcher: $this->createStub(\Psr\EventDispatcher\EventDispatcherInterface::class),
            logger: new \Psr\Log\NullLogger(),
            challenges: new \App\Tests\Fixtures\Auth\InMemoryPasskeyChallengeStore(),
            supportedAlgorithmIds: [-7, -257],
            jsonEncoder: new \Symfony\Component\Serializer\Encoder\JsonEncoder(),
        );
    }

    /**
     * The credential as the web client serializes it: the browser's base64url `id`, and the raw
     * ID and response fields in standard, padded base64.
     *
     * @param array<string, mixed> $credential
     *
     * @return array<string, mixed>
     */
    private static function asTheWebClientSendsIt(array $credential): array
    {
        $standard = static fn (string $base64Url): string => base64_encode((string) base64_decode(strtr($base64Url, '-_', '+/'), true));

        return [
            ...$credential,
            'rawId' => $standard($credential['rawId']),
            'response' => array_map($standard, $credential['response']),
        ];
    }

    /** A step-up check that the password always passes, for an account without TOTP. */
    private function stepUp(): StepUpVerifier
    {
        $hasher = $this->createStub(PasswordHasherInterface::class);
        $hasher->method('verify')->willReturn(true);

        return new StepUpVerifier(
            $this->createStub(StepUpAttemptThrottleInterface::class),
            $hasher,
            $this->createStub(TotpVerifierInterface::class),
            new \Psr\Log\NullLogger(),
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
