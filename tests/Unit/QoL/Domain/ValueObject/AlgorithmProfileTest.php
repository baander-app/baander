<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Domain\ValueObject;

use App\QoL\Domain\ValueObject\AlgorithmProfile;
use PHPUnit\Framework\TestCase;
use ValueError;

final class AlgorithmProfileTest extends TestCase
{
    public function testHasExactlyThreeCases(): void
    {
        $this->assertCount(3, AlgorithmProfile::cases());
    }

    public function testCaseStringValues(): void
    {
        $this->assertSame('conservative', AlgorithmProfile::Conservative->value);
        $this->assertSame('balanced', AlgorithmProfile::Balanced->value);
        $this->assertSame('aggressive', AlgorithmProfile::Aggressive->value);
    }

    public function testFromResolvesKnownValues(): void
    {
        $this->assertSame(AlgorithmProfile::Conservative, AlgorithmProfile::from('conservative'));
        $this->assertSame(AlgorithmProfile::Balanced, AlgorithmProfile::from('balanced'));
        $this->assertSame(AlgorithmProfile::Aggressive, AlgorithmProfile::from('aggressive'));
    }

    public function testTryFromResolvesKnownValue(): void
    {
        $this->assertSame(AlgorithmProfile::Aggressive, AlgorithmProfile::tryFrom('aggressive'));
    }

    public function testTryFromReturnsNullForUnknownValue(): void
    {
        // Value sourced from a method with a general `string` return type so
        // PHPStan cannot narrow tryFrom()'s return to a known-literal case.
        $this->assertNull(AlgorithmProfile::tryFrom(self::unknownProfileValue()));
    }

    private static function unknownProfileValue(): string
    {
        return 'turbo';
    }

    public function testFromThrowsValueErrorForUnknownValue(): void
    {
        $this->expectException(ValueError::class);

        AlgorithmProfile::from('turbo');
    }

    public function testExactLabelStrings(): void
    {
        $this->assertSame('Conservative (70%)', AlgorithmProfile::Conservative->label());
        $this->assertSame('Balanced (80%)', AlgorithmProfile::Balanced->label());
        $this->assertSame('Aggressive (90%)', AlgorithmProfile::Aggressive->label());
    }
}
