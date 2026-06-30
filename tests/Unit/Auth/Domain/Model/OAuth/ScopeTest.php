<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model\ValueObject;

use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ScopeTest extends TestCase
{
    public function testValidScope(): void
    {
        $scope = new Scope('access-api');

        $this->assertSame('access-api', $scope->toString());
    }

    public function testSingleCharScope(): void
    {
        $scope = new Scope('a');

        $this->assertSame('a', $scope->toString());
    }

    public function testEmptyScopeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Scope('');
    }

    public function testTooLongScopeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Scope(str_repeat('a', 65));
    }

    public function testUppercaseNormalized(): void
    {
        $scope = new Scope('Invalid');

        $this->assertSame('invalid', $scope->toString());
    }

    public function testSpecialCharsThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Scope('scope_invalid');
    }

    public function testHyphenAndDotAllowed(): void
    {
        $scope = new Scope('my-scope.v2');

        $this->assertSame('my-scope.v2', $scope->toString());
    }

    public function testWhitespaceTrimmed(): void
    {
        $scope = new Scope('  profile  ');

        $this->assertSame('profile', $scope->toString());
    }

    public function testFromString(): void
    {
        $scope = Scope::fromString('admin');

        $this->assertSame('admin', $scope->toString());
    }

    public function testDefaultScopes(): void
    {
        $defaults = Scope::defaultScopes();

        $this->assertCount(1, $defaults);
        $this->assertSame('access-api', $defaults[0]->toString());
    }

    public function testStaticFactories(): void
    {
        $this->assertSame('access-api', Scope::accessApi()->toString());
        $this->assertSame('profile', Scope::profile()->toString());
        $this->assertSame('admin', Scope::admin()->toString());
    }

    public function testEquals(): void
    {
        $s1 = new Scope('admin');
        $s2 = new Scope('admin');
        $s3 = new Scope('profile');

        $this->assertTrue($s1->equals($s2));
        $this->assertFalse($s1->equals($s3));
    }

    public function testToStringMagic(): void
    {
        $scope = new Scope('test');

        $this->assertSame('test', (string) $scope);
    }
}
