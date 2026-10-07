<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class PasswordResetEndpointTest extends TestCase
{
    public function testResetSetsTheNewPasswordAndConsumesTheToken(): void
    {
        $user = $this->createTestUser();
        $this->tokens()->issue($user->getId(), 'valid-token', new \DateTimeImmutable('+1 hour'));

        $response = $this->reset('valid-token', 'brand-new-password');

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertSame('Your password has been reset.', $data['data']['message']);
        $this->assertPassword($user->getId(), 'brand-new-password');
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM password_reset_tokens'));

        $this->assertSame($this->invalidTokenBody(), $this->reset('valid-token', 'another-password')->getContent(), 'A used token gets the generic error.');
        $this->assertPassword($user->getId(), 'brand-new-password');
    }

    public function testAnExpiredTokenGetsTheGenericError(): void
    {
        $user = $this->createTestUser();
        $this->tokens()->issue($user->getId(), 'expired-token', new \DateTimeImmutable('-1 second'));

        $response = $this->reset('expired-token', 'brand-new-password');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame($this->invalidTokenBody(), $response->getContent());
        $this->assertPassword($user->getId(), 'password123');
    }

    public function testATokenFromADeletedAndRecreatedAccountIsRejected(): void
    {
        $email = 'recreated-' . bin2hex(random_bytes(4)) . '@baander.app';
        $deleted = $this->createTestUser($email);
        $this->tokens()->issue($deleted->getId(), 'orphaned-token', new \DateTimeImmutable('+1 hour'));
        $this->userRepository->delete($deleted->getId());
        $recreated = $this->createTestUser($email, password: 'recreated-password');

        $response = $this->reset('orphaned-token', 'attacker-password');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame($this->invalidTokenBody(), $response->getContent());
        $this->assertPassword($recreated->getId(), 'recreated-password');
    }

    public function testADisabledAccountCannotBeReset(): void
    {
        $user = $this->createTestUser();
        $user->disable();
        $this->userRepository->save($user);
        $this->tokens()->issue($user->getId(), 'disabled-token', new \DateTimeImmutable('+1 hour'));

        $response = $this->reset('disabled-token', 'brand-new-password');

        $this->assertSame($this->invalidTokenBody(), $response->getContent());
        $this->assertPassword($user->getId(), 'password123');
    }

    public function testTheNewPasswordFollowsThePasswordPolicy(): void
    {
        $user = $this->createTestUser();
        $this->tokens()->issue($user->getId(), 'policy-token', new \DateTimeImmutable('+1 hour'));

        $this->assertJsonResponse($this->reset('policy-token', 'short'), 422);
        $this->assertJsonResponse($this->reset('policy-token', str_repeat('a', 256)), 422);

        $this->assertJsonResponse($this->reset('policy-token', 'long-enough'), 200);
    }

    public function testARequestedTokenLastsTheConfiguredLifetime(): void
    {
        $user = $this->createTestUser();
        $minutes = static::getContainer()->getParameter('auth.password_reset_token.lifetime_minutes');
        $this->assertIsInt($minutes);

        $before = time();
        $this->assertJsonResponse($this->anonymousRequest('POST', '/api/auth/password/reset-request', ['email' => $user->getEmail()]), 200);
        $after = time();

        $expiresAt = $this->entityManager->getConnection()->fetchOne(
            'SELECT extract(epoch FROM expires_at)::bigint FROM password_reset_tokens WHERE user_id = ?',
            [$user->getId()->toString()],
        );
        $this->assertGreaterThanOrEqual($before + $minutes * 60, (int) $expiresAt);
        $this->assertLessThanOrEqual($after + $minutes * 60, (int) $expiresAt);
    }

    private function reset(string $token, string $password): Response
    {
        return $this->anonymousRequest('POST', '/api/auth/password/reset', ['token' => $token, 'password' => $password]);
    }

    private function invalidTokenBody(): string|false
    {
        $response = $this->reset('never-issued-' . bin2hex(random_bytes(4)), 'brand-new-password');
        $data = $this->assertJsonResponse($response, 400, 'error');
        $this->assertSame('This password reset link is invalid or has expired.', $data['error']['message']);

        return $response->getContent();
    }

    private function assertPassword(Uuid $userId, string $expected): void
    {
        $this->entityManager->clear();
        $user = $this->userRepository->findByUuid($userId);
        $this->assertNotNull($user);
        $hasher = static::getContainer()->get(PasswordHasherInterface::class);
        $this->assertInstanceOf(PasswordHasherInterface::class, $hasher);
        $this->assertTrue($hasher->verify($expected, $user->getPassword()));
    }

    private function tokens(): PasswordResetTokenRepositoryInterface
    {
        $repository = static::getContainer()->get(PasswordResetTokenRepositoryInterface::class);
        $this->assertInstanceOf(PasswordResetTokenRepositoryInterface::class, $repository);

        return $repository;
    }
}
