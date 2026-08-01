<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\Voter;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Infrastructure\Security\Voter\AdminVoter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class AdminVoterTest extends TestCase
{
    private AdminVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new AdminVoter();
    }

    private function createToken(string $userId, array $roles): TokenInterface&MockObject
    {
        $user = new SecurityUser($userId, 'user@example.com', 'hashed', $roles);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getRoleNames')->willReturn($roles);

        return $token;
    }

    // --- supports() ---

    public function testSupportsAdminAccessAttribute(): void
    {
        $token = $this->createToken('user-1', ['ROLE_USER']);
        $result = $this->voter->vote($token, null, [AdminVoter::ADMIN_ACCESS]);

        // ACCESS_GRANTED or ACCESS_DENIED means supports; ACCESS_ABSTAIN means does not support
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testAbstainsOnOtherAttributes(): void
    {
        $token = $this->createToken('user-1', ['ROLE_USER']);
        $result = $this->voter->vote($token, null, ['VIEW']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    // --- voteOnAttribute() ---

    public function testAdminGetsAccessGranted(): void
    {
        $token = $this->createToken('user-123', ['ROLE_USER', 'ROLE_ADMIN']);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, null, [AdminVoter::ADMIN_ACCESS]),
        );
    }

    public function testNonAdminGetsAccessDenied(): void
    {
        $token = $this->createToken('user-456', ['ROLE_USER']);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, null, [AdminVoter::ADMIN_ACCESS]),
        );
    }

    public function testAnonymousUserGetsAccessDenied(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);
        $token->method('getRoleNames')->willReturn([]);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, null, [AdminVoter::ADMIN_ACCESS]),
        );
    }

    public function testAbstainsOnMultipleAttributesWithMixedSupport(): void
    {
        $token = $this->createToken('user-1', ['ROLE_ADMIN']);

        // When one attribute is supported and one is not, voter should still decide
        $result = $this->voter->vote($token, null, [AdminVoter::ADMIN_ACCESS, 'VIEW']);

        // Symfony Voter returns ACCESS_GRANTED if all supported attributes pass
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    // --- USER_MANAGEMENT attribute ---

    public function testSupportsUserManagementAttribute(): void
    {
        $token = $this->createToken('user-1', ['ROLE_USER']);
        $result = $this->voter->vote($token, null, [AdminVoter::USER_MANAGEMENT]);

        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testSuperAdminGetsUserManagementGranted(): void
    {
        $token = $this->createToken('sa-1', ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, null, [AdminVoter::USER_MANAGEMENT]),
        );
    }

    public function testAdminGetsUserManagementDenied(): void
    {
        $token = $this->createToken('admin-1', ['ROLE_USER', 'ROLE_ADMIN']);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, null, [AdminVoter::USER_MANAGEMENT]),
        );
    }

    // --- SYSTEM_SETTINGS attribute ---

    public function testSupportsSystemSettingsAttribute(): void
    {
        $token = $this->createToken('user-1', ['ROLE_USER']);
        $result = $this->voter->vote($token, null, [AdminVoter::SYSTEM_SETTINGS]);

        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testSuperAdminGetsSystemSettingsGranted(): void
    {
        $token = $this->createToken('sa-1', ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, null, [AdminVoter::SYSTEM_SETTINGS]),
        );
    }

    public function testAdminGetsSystemSettingsDenied(): void
    {
        $token = $this->createToken('admin-1', ['ROLE_USER', 'ROLE_ADMIN']);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, null, [AdminVoter::SYSTEM_SETTINGS]),
        );
    }

    // --- SUPER_ADMIN gets ADMIN_ACCESS too ---

    public function testSuperAdminGetsAdminAccessGranted(): void
    {
        $token = $this->createToken('sa-1', ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, null, [AdminVoter::ADMIN_ACCESS]),
        );
    }
}
