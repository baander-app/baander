<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;

/**
 * An outstanding password reset token belongs to one account and ends with that account's
 * current credentials: deleting the account, or changing its email or password, removes it.
 */
final class PasswordResetTokenLifecycleTest extends TestCase
{
    public function testRequestTiesTheTokenToTheUser(): void
    {
        $user = $this->createTestUser($this->uniqueEmail());

        $this->requestReset($user->getEmail());

        $owners = $this->entityManager->getConnection()->fetchFirstColumn('SELECT user_id FROM password_reset_tokens');
        $this->assertSame([$user->getId()->toString()], $owners);
    }

    public function testRequestForAnUnknownAddressGivesTheSameResponseAndIssuesNoToken(): void
    {
        $known = $this->createTestUser($this->uniqueEmail());

        $knownResponse = $this->requestReset($known->getEmail());
        $unknownResponse = $this->requestReset($this->uniqueEmail());

        $this->assertSame($knownResponse, $unknownResponse);
        $this->assertSame(1, $this->outstandingTokens());
    }

    public function testDeletingTheUserRemovesTheirToken(): void
    {
        $user = $this->createTestUser($this->uniqueEmail());
        $this->requestReset($user->getEmail());

        $this->userRepository->delete($user->getId());

        $this->assertSame(0, $this->outstandingTokens());
    }

    public function testATokenDoesNotCarryOverToALaterAccountWithTheSameEmail(): void
    {
        $email = $this->uniqueEmail();
        $first = $this->createTestUser($email);
        $this->requestReset($email);
        $this->userRepository->delete($first->getId());

        $this->createTestUser($email);

        $this->assertSame(0, $this->outstandingTokens());
    }

    public function testChangingTheEmailRemovesTheToken(): void
    {
        $user = $this->createTestUser($this->uniqueEmail());
        $this->requestReset($user->getEmail());

        $response = $this->authenticatedRequest('PUT', '/api/auth/me/email', $user, ['email' => $this->uniqueEmail()]);

        $this->assertJsonResponse($response, 200);
        $this->assertSame(0, $this->outstandingTokens());
    }

    public function testChangingThePasswordRemovesTheToken(): void
    {
        $user = $this->createTestUser($this->uniqueEmail());
        $this->requestReset($user->getEmail());

        $response = $this->authenticatedRequest('PUT', '/api/auth/me/password', $user, [
            'currentPassword' => 'password123',
            'newPassword' => 'another-password-456',
        ]);

        $this->assertJsonResponse($response, 200);
        $this->assertSame(0, $this->outstandingTokens());
    }

    public function testAnAdministratorPasswordResetRemovesTheToken(): void
    {
        $user = $this->createTestUser($this->uniqueEmail());
        $this->requestReset($user->getEmail());

        $response = $this->authenticatedRequest(
            'POST',
            '/api/admin/users/' . $user->getId()->toString() . '/reset-password',
            $this->createSuperAdminUser(),
            ['password' => 'operator-chosen-789'],
        );

        $this->assertJsonResponse($response, 200);
        $this->assertSame(0, $this->outstandingTokens());
    }

    public function testRenamingTheUserKeepsTheToken(): void
    {
        $user = $this->createTestUser($this->uniqueEmail());
        $this->requestReset($user->getEmail());

        $reloaded = $this->userRepository->findByUuid($user->getId());
        $this->assertInstanceOf(User::class, $reloaded);
        $reloaded->updateName('Renamed User');
        $this->userRepository->save($reloaded);

        $this->assertSame(1, $this->outstandingTokens());
    }

    /** @return array<string, mixed> the decoded response body */
    private function requestReset(string $email): array
    {
        $response = $this->anonymousRequest('POST', '/api/auth/password/reset-request', ['email' => $email]);

        return $this->assertJsonResponse($response, 200, 'data');
    }

    private function outstandingTokens(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM password_reset_tokens');
    }

    private function uniqueEmail(): string
    {
        return 'reset-' . bin2hex(random_bytes(6)) . '@baander.app';
    }
}
