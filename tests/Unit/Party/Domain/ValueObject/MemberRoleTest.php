<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Domain\ValueObject;

use App\Party\Domain\ValueObject\MemberRole;
use PHPUnit\Framework\TestCase;
use ValueError;

final class MemberRoleTest extends TestCase
{
    public function testHostValue(): void
    {
        $this->assertSame('host', MemberRole::Host->value);
    }

    public function testHostLabel(): void
    {
        $this->assertSame('Host', MemberRole::Host->label());
    }

    public function testMemberValue(): void
    {
        $this->assertSame('member', MemberRole::Member->value);
    }

    public function testMemberLabel(): void
    {
        $this->assertSame('Member', MemberRole::Member->label());
    }

    public function testCasesCount(): void
    {
        $this->assertCount(2, MemberRole::cases());
    }

    public function testCasesContainBothRoles(): void
    {
        $cases = MemberRole::cases();

        $this->assertContains(MemberRole::Host, $cases);
        $this->assertContains(MemberRole::Member, $cases);
    }

    public function testFromValidStringReturnsHost(): void
    {
        $this->assertSame(MemberRole::Host, MemberRole::from('host'));
    }

    public function testFromValidStringReturnsMember(): void
    {
        $this->assertSame(MemberRole::Member, MemberRole::from('member'));
    }

    public function testFromInvalidStringThrowsValueError(): void
    {
        $this->expectException(ValueError::class);

        MemberRole::from('invalid_role');
    }
}
