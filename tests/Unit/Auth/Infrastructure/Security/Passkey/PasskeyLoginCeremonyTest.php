<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\Passkey;

use App\Auth\Application\Command\Passkey\AuthenticatePasskeyCommand;
use App\Auth\Application\CommandHandler\Passkey\AuthenticatePasskeyHandler;
use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\Passkey\AuthenticatorCounterChecker;
use App\Auth\Infrastructure\Security\Passkey\PasskeyService;
use App\Shared\Domain\Model\Email;
use App\Tests\Fixtures\Auth\InMemoryPasskeyChallengeStore;
use App\Tests\Fixtures\Auth\WebAuthnTestCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/** Passkey sign-in through the real WebAuthn library, with real signed assertions. */
final class PasskeyLoginCeremonyTest extends TestCase
{
    private const string ORIGIN = 'https://baander.app';

    private InMemoryPasskeyChallengeStore $challenges;
    private User $user;

    protected function setUp(): void
    {
        $this->challenges = new InMemoryPasskeyChallengeStore();
        $this->user = User::register(new Email('passkey-ceremony@baander.app'), 'hash', 'Owner');
    }

    public function testALoginChallengeIsSpentOnceEvenFromAnotherServiceInstance(): void
    {
        $credential = new WebAuthnTestCredential($this->user->getPublicId()->toString(), 'baander.app');
        $passkey = $credential->passkey();
        $issuer = $this->service();
        $options = $issuer->createAuthenticationOptions();
        $challenge = $options['options']['challenge'];

        self::assertSame($this->user->getId()->toString(), $this->signIn($this->service(), $passkey, $options['challengeKey'], $credential->assert($challenge, self::ORIGIN)));

        $this->expectExceptionObject(new RuntimeException('Challenge not found or has expired.'));
        $this->signIn($issuer, $passkey, $options['challengeKey'], $credential->assert($challenge, self::ORIGIN));
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function userVerification(): iterable
    {
        yield 'TOTP on, user verified' => [true, true, true];
        yield 'TOTP on, user not verified' => [true, false, false];
        yield 'TOTP off, user verified' => [false, true, true];
        yield 'TOTP off, user not verified' => [false, false, true];
    }

    #[DataProvider('userVerification')]
    public function testAnAccountWithTotpOnNeedsAVerifiedUser(bool $totp, bool $verifiesUser, bool $accepted): void
    {
        if ($totp) {
            $this->user->enableTotp('sealed-totp-secret');
        }
        $credential = new WebAuthnTestCredential($this->user->getPublicId()->toString(), 'baander.app', verifiesUser: $verifiesUser);
        $passkey = $credential->passkey();
        $service = $this->service();
        $options = $service->createAuthenticationOptions();
        $assertion = $credential->assert($options['options']['challenge'], self::ORIGIN);

        try {
            $userId = $this->signIn($service, $passkey, $options['challengeKey'], $assertion);
        } catch (RuntimeException $error) {
            self::assertFalse($accepted, $error->getMessage());
            self::assertSame('This account requires user verification on the passkey.', $error->getMessage());
            self::assertSame(0, $passkey->getCounter(), 'A refused assertion does not move the counter.');

            return;
        }
        self::assertTrue($accepted, 'An account with TOTP on must not accept a passkey that did not verify the user.');
        self::assertSame($this->user->getId()->toString(), $userId);
    }

    public function testAPasskeyWhoseCounterStaysAtZeroSignsInRepeatedly(): void
    {
        $credential = new WebAuthnTestCredential($this->user->getPublicId()->toString(), 'baander.app', counts: false);
        $passkey = $credential->passkey();
        $service = $this->service();

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $options = $service->createAuthenticationOptions();
            $assertion = $credential->assert($options['options']['challenge'], self::ORIGIN);

            self::assertSame($this->user->getId()->toString(), $this->signIn($service, $passkey, $options['challengeKey'], $assertion));
        }
        self::assertSame(0, $passkey->getCounter());
    }

    public function testACounterThatGoesBackwardsIsRefused(): void
    {
        $credential = new WebAuthnTestCredential($this->user->getPublicId()->toString(), 'baander.app');
        $passkey = $credential->passkey();
        $passkey->updateCounter(5);
        $service = $this->service();
        $options = $service->createAuthenticationOptions();
        $assertion = $credential->assert($options['options']['challenge'], self::ORIGIN);

        try {
            $this->signIn($service, $passkey, $options['challengeKey'], $assertion);
            self::fail('A counter below the stored one points at a cloned authenticator.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('cloned', $error->getMessage());
        }
        self::assertSame(5, $passkey->getCounter());
    }

    /** @param array<string, mixed> $assertion */
    private function signIn(PasskeyService $service, Passkey $passkey, string $challengeKey, array $assertion): string
    {
        $passkeys = $this->createStub(PasskeyRepositoryInterface::class);
        $passkeys->method('ofCredentialId')->willReturn($passkey);
        $passkeys->method('userIdForCredentialId')->willReturn($this->user->getId());
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturn($this->user);

        return new AuthenticatePasskeyHandler($passkeys, $service, $users)(new AuthenticatePasskeyCommand(null, $challengeKey, $assertion));
    }

    /** One worker's service; every instance shares the test's challenge store, as workers share Redis. */
    private function service(): PasskeyService
    {
        return new PasskeyService(
            appDomain: 'baander.app',
            appName: 'Baander',
            timeout: 60000,
            authenticatorAttachment: '',
            userVerification: 'preferred',
            residentKey: 'preferred',
            attestation: 'none',
            counterChecker: new AuthenticatorCounterChecker(new NullLogger()),
            eventDispatcher: $this->createStub(EventDispatcherInterface::class),
            logger: new NullLogger(),
            challenges: $this->challenges,
            supportedAlgorithmIds: [-7],
            jsonEncoder: new JsonEncoder(),
        );
    }
}
