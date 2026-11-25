<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\Service;

use App\Auth\Application\Exception\StepUpFailedException;
use App\Auth\Application\Exception\StepUpRateLimitedException;
use App\Auth\Application\Exception\TotpSecretUnavailableException;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Port\StepUpAttemptThrottleInterface;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Application\Service\StepUpVerifier;
use App\Auth\Domain\Model\User;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class StepUpVerifierTest extends TestCase
{
    private const string PASSWORD = 'current-password';

    /** @var list<string> */
    private array $spentCodes = [];

    public function testThePasswordAloneConfirmsAnAccountWithoutTotp(): void
    {
        $this->verifier()->verify($this->user(totp: false), self::PASSWORD, null);

        self::assertSame([], $this->spentCodes);
    }

    public function testAnAccountWithTotpNeedsTheCodeAndSpendsIt(): void
    {
        $this->verifier(acceptedCode: '123456')->verify($this->user(totp: true), self::PASSWORD, '123456');

        self::assertSame(['123456'], $this->spentCodes);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function mismatches(): iterable
    {
        yield 'wrong password' => ['not-the-password', '123456'];
        yield 'no code' => [self::PASSWORD, null];
        yield 'empty code' => [self::PASSWORD, ''];
        yield 'wrong code' => [self::PASSWORD, '654321'];
    }

    #[DataProvider('mismatches')]
    public function testEveryMismatchGetsTheSameRefusal(string $password, ?string $code): void
    {
        try {
            $this->verifier(acceptedCode: '123456')->verify($this->user(totp: true), $password, $code);
            self::fail('The step-up passed.');
        } catch (StepUpFailedException $refusal) {
            self::assertSame(['reason' => 'step_up_failed'], $refusal->details);
            self::assertSame('The password or code is incorrect.', $refusal->getMessage());
        }
    }

    public function testAWrongPasswordSpendsNoCode(): void
    {
        try {
            $this->verifier(acceptedCode: '123456')->verify($this->user(totp: true), 'not-the-password', '123456');
        } catch (StepUpFailedException) {
        }

        self::assertSame([], $this->spentCodes);
    }

    public function testASecretThatDoesNotOpenIsRefused(): void
    {
        $this->expectException(StepUpFailedException::class);

        $this->verifier(secretUnavailable: true)->verify($this->user(totp: true), self::PASSWORD, '123456');
    }

    public function testALimitedAccountIsRefusedBeforeThePasswordIsChecked(): void
    {
        $throttle = $this->createStub(StepUpAttemptThrottleInterface::class);
        $throttle->method('acquire')->willThrowException(new StepUpRateLimitedException(30));
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::never())->method('verify');

        $this->expectException(StepUpRateLimitedException::class);

        (new StepUpVerifier($throttle, $hasher, $this->createStub(TotpVerifierInterface::class), new NullLogger()))
            ->verify($this->user(totp: false), self::PASSWORD, null);
    }

    private function verifier(?string $acceptedCode = null, bool $secretUnavailable = false): StepUpVerifier
    {
        $hasher = $this->createStub(PasswordHasherInterface::class);
        $hasher->method('verify')->willReturnCallback(static fn (string $plain): bool => $plain === self::PASSWORD);
        $totp = $this->createStub(TotpVerifierInterface::class);
        $totp->method('verifyAndSpend')->willReturnCallback(function (User $user, string $code) use ($acceptedCode, $secretUnavailable): bool {
            if ($secretUnavailable) {
                throw TotpSecretUnavailableException::forStoredSecret();
            }
            $this->spentCodes[] = $code;

            return $code === $acceptedCode;
        });

        return new StepUpVerifier($this->createStub(StepUpAttemptThrottleInterface::class), $hasher, $totp, new NullLogger());
    }

    private function user(bool $totp): User
    {
        $user = User::register(new Email('step-up@baander.app'), 'hash', 'Step Up');
        if ($totp) {
            $user->enableTotp('sealed-secret');
        }

        return $user;
    }
}
