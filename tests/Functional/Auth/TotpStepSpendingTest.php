<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Application\Port\TotpSecretCipherInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Infrastructure\Security\Totp\TotpService;
use App\Auth\Infrastructure\Security\Totp\TotpVerifier;
use App\Tests\Functional\TestCase;
use OTPHP\TOTP;
use Symfony\Component\Clock\MockClock;

/**
 * Each TOTP time step counts once per account, against the users row on PostgreSQL. The
 * verifier runs on a mock clock at step N with the configured window of one step.
 */
final class TotpStepSpendingTest extends TestCase
{
    private const string SECRET = 'JBSWY3DPEHPK3PXP';
    private const int STEP = 58_000_000;

    private MockClock $clock;
    private TotpVerifier $verifier;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $cipher = static::getContainer()->get(TotpSecretCipherInterface::class);
        self::assertInstanceOf(TotpSecretCipherInterface::class, $cipher);
        $this->clock = new MockClock(new \DateTimeImmutable('@' . (self::STEP * 30 + 5)));
        $this->verifier = new TotpVerifier(new TotpService($this->clock), $cipher, $this->userRepository);
        $this->user = $this->createTestUser();
        $this->user->enableTotp($cipher->seal(self::SECRET));
        $this->userRepository->save($this->user);
    }

    public function testACodeFromThePreviousStepIsAcceptedOnce(): void
    {
        $this->assertTrue($this->verifier->verifyAndSpend($this->user, self::code(self::STEP - 1)));
        $this->assertFalse($this->verifier->verifyAndSpend($this->user, self::code(self::STEP - 1)));
    }

    public function testACodeTwoStepsOldIsRefused(): void
    {
        $this->assertFalse($this->verifier->verifyAndSpend($this->user, self::code(self::STEP - 2)));
    }

    public function testANextStepCodeAcceptedNowIsRefusedWhenItsStepArrives(): void
    {
        $this->assertTrue($this->verifier->verifyAndSpend($this->user, self::code(self::STEP + 1)));

        $this->clock->sleep(30);

        $this->assertFalse($this->verifier->verifyAndSpend($this->user, self::code(self::STEP + 1)));
        $this->assertFalse($this->verifier->verifyAndSpend($this->user, self::code(self::STEP)), 'An earlier step is spent with a later one.');
    }

    /** Saving the account after a spend does not move the recorded step back. */
    public function testSavingTheAccountKeepsTheSpentStep(): void
    {
        $this->assertTrue($this->verifier->verifyAndSpend($this->user, self::code(self::STEP)));
        $this->userRepository->save($this->user);

        $this->assertFalse($this->verifier->verifyAndSpend($this->user, self::code(self::STEP)));
        $this->assertSame(self::STEP, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT totp_last_used_step FROM users WHERE id = ?',
            [$this->user->getId()->toString()],
        ));
    }

    private static function code(int $step): string
    {
        return TOTP::create(self::SECRET, clock: new MockClock())->at($step * 30);
    }
}
