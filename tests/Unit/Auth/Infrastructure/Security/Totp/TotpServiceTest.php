<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\Totp\TotpService;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TotpServiceTest extends TestCase
{
    private const string SECRET = 'JBSWY3DPEHPK3PXP';
    /** A moment ten seconds into step 58,000,000. */
    private const int NOW = 58_000_000 * 30 + 10;

    private MockClock $clock;
    private TotpService $service;

    protected function setUp(): void
    {
        $this->clock = new MockClock(new \DateTimeImmutable('@' . self::NOW));
        $this->service = new TotpService($this->clock);
    }

    public function testGenerateSecretReturnsBase32(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $this->service->generateSecret());
    }

    public function testGetProvisioningUri(): void
    {
        $secret = $this->service->generateSecret();
        $uri = $this->service->getProvisioningUri($secret, 'user@baander.app');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertSame('/Bånder:user@baander.app', rawurldecode((string) parse_url($uri, PHP_URL_PATH)));
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $parameters);
        $this->assertSame('Bånder', $parameters['issuer']);
        $this->assertSame($secret, $parameters['secret']);
    }

    /** The window counts 30-second steps, not seconds: one step on either side matches. */
    public function testTheWindowAcceptsTheStepsOnEitherSideOfTheCurrentOne(): void
    {
        $this->assertSame(57_999_999, $this->service->matchStep(self::SECRET, self::code(57_999_999)));
        $this->assertSame(58_000_000, $this->service->matchStep(self::SECRET, self::code(58_000_000)));
        $this->assertSame(58_000_001, $this->service->matchStep(self::SECRET, self::code(58_000_001)));
    }

    public function testACodeTwoStepsOldOrAheadMatchesNothing(): void
    {
        $this->assertNull($this->service->matchStep(self::SECRET, self::code(57_999_998)));
        $this->assertNull($this->service->matchStep(self::SECRET, self::code(58_000_002)));
    }

    /** A code for the next step accepted now matches that same step once its time comes, so it is spent once. */
    public function testANextStepCodeMatchesTheSameStepWhenItsTimeComes(): void
    {
        $code = self::code(58_000_001);
        $now = $this->service->matchStep(self::SECRET, $code);

        $this->clock->sleep(30);
        $whenItsStepArrives = $this->service->matchStep(self::SECRET, $code);

        $this->assertSame([58_000_001, 58_000_001], [$now, $whenItsStepArrives]);
    }

    public function testAWindowOfZeroAcceptsOnlyTheCurrentStep(): void
    {
        $service = new TotpService($this->clock, window: 0);

        $this->assertSame(58_000_000, $service->matchStep(self::SECRET, self::code(58_000_000)));
        $this->assertNull($service->matchStep(self::SECRET, self::code(58_000_001)));
    }

    public function testAWrongCodeMatchesNothing(): void
    {
        $code = self::code(58_000_000) === '000000' ? '000001' : '000000';

        $this->assertNull($this->service->matchStep(self::SECRET, $code));
    }

    private static function code(int $step): string
    {
        return TOTP::create(self::SECRET, clock: new MockClock())->at($step * 30);
    }
}
