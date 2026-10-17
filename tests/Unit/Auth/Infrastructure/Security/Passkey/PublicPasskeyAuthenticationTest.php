<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\Passkey;

use App\Auth\Application\Command\Passkey\AuthenticatePasskeyCommand;
use App\Auth\Application\CommandHandler\Passkey\AuthenticatePasskeyHandler;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\Passkey\PasskeyService;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\Auth\WebAuthnTestCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Webauthn\Counter\CounterChecker;

final class PublicPasskeyAuthenticationTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function handles(): iterable
    {
        yield 'discoverable public handle' => ['public', true];
        yield 'legacy internal UUID handle' => ['uuid', false];
        yield 'valid foreign stored public handle' => ['foreign-stored', false];
        yield 'foreign assertion handle' => ['foreign-assertion', false];
        yield 'invalid cryptographic signature' => ['invalid-signature', false];
    }

    #[DataProvider('handles')]
    public function testRealSignedAssertionMustMatchPublicIdentityOfPersistedOwner(string $kind, bool $accepted): void
    {
        $user = User::register(new Email('passkey@baander.app'), 'hash', 'Owner');
        $handle = match ($kind) {
            'uuid' => $user->getId()->toString(),
            'foreign-stored' => (new PublicId())->toString(),
            default => $user->getPublicId()->toString(),
        };
        $credential = new WebAuthnTestCredential($handle, 'baander.app');
        $passkey = $credential->passkey();
        $service = new PasskeyService(
            appDomain: 'baander.app', appName: 'Baander', timeout: 60000,
            authenticatorAttachment: '', userVerification: 'preferred', residentKey: 'preferred', attestation: 'none',
            counterChecker: $this->createStub(CounterChecker::class),
            eventDispatcher: $this->createStub(EventDispatcherInterface::class),
            logger: new NullLogger(), cache: new ArrayAdapter(), supportedAlgorithmIds: [-7], jsonEncoder: new JsonEncoder(),
        );
        $options = $service->createAuthenticationOptions();
        $assertion = $credential->assert($options['options']['challenge'], 'https://baander.app');
        if ($kind === 'foreign-assertion') {
            $assertion['response']['userHandle'] = rtrim(strtr(base64_encode((new PublicId())->toString()), '+/', '-_'), '=');
        }
        if ($kind === 'invalid-signature') {
            $assertion['response']['signature'] = rtrim(strtr(base64_encode(str_repeat('x', 64)), '+/', '-_'), '=');
        }
        $repository = $this->createMock(PasskeyRepositoryInterface::class);
        $repository->method('ofCredentialId')->willReturn($passkey);
        $repository->method('userIdForCredentialId')->willReturn($user->getId());
        $repository->expects($accepted ? self::once() : self::never())->method('markUsed');
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturn($user);
        $handler = new AuthenticatePasskeyHandler($repository, $service, $users);

        try {
            $id = $handler(new AuthenticatePasskeyCommand(null, $options['challengeKey'], $assertion));
        } catch (\Throwable $error) {
            if ($accepted) {
                throw $error;
            }
            if ($kind === 'uuid') {
                self::assertInstanceOf(\InvalidArgumentException::class, $error);
            } elseif ($kind === 'foreign-stored') {
                self::assertInstanceOf(\RuntimeException::class, $error);
                self::assertSame('Credential owner does not match its registered user handle.', $error->getMessage());
            } elseif ($kind === 'foreign-assertion') {
                self::assertInstanceOf(\Webauthn\Exception\InvalidUserHandleException::class, $error);
            } else {
                self::assertInstanceOf(\Webauthn\Exception\AuthenticatorResponseVerificationException::class, $error);
            }
            self::assertSame(0, $passkey->getCounter());
            return;
        }
        self::assertTrue($accepted, 'A legacy or foreign handle must not authenticate.');
        self::assertSame($user->getId()->toString(), $id, 'Only the internal command result retains the internal identity.');
        self::assertSame(1, $passkey->getCounter());
    }
}
