<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\FirstPartyOAuthClient;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

/**
 * A password reset goes to the account's current address. Redeemed on an address that was
 * never verified, it settles who owns the account: the address becomes verified, and the
 * passkeys, TOTP and sessions of whoever registered it are gone.
 */
final class PasswordResetClaimTest extends RateLimitTestCase
{
    use EnrolsTotp;

    private const string IP = '198.51.100.90';
    private const string LOGIN_URI = 'http://localhost/api/auth/login';

    protected function setUp(): void
    {
        parent::setUp();
        $this->trackLimiter('auth_password_reset_ip', self::IP);
    }

    public function testAResetToANeverVerifiedAddressHandsTheAccountToTheAddressOwner(): void
    {
        FirstPartyOAuthClient::entity(static::getContainer(), $this->entityManager);
        $address = 'claimed-' . bin2hex(random_bytes(4)) . '@baander.app';
        $registrant = $this->createTestUser($address, password: 'registrant-password-1');
        $this->assertSame(200, $this->login($address, 'registrant-password-1')->getStatusCode(), 'The registrant has a session.');
        $this->enrolTotp($registrant);
        $this->passkeys()->save(Passkey::create(new Uuid(), 'Registrant key', bin2hex(random_bytes(16)), [], 0), $registrant->getId());
        $this->assertJsonResponse($this->authenticatedRequest('PUT', '/api/auth/me/email', $registrant, ['email' => 'elsewhere-' . bin2hex(random_bytes(4)) . '@baander.app', 'currentPassword' => 'registrant-password-1', 'totpCode' => self::currentTotpCode()]), 202);

        $this->assertJsonResponse($this->requestFrom(self::IP, 'POST', '/api/auth/password/reset-request', content: ['email' => $address]), 200);
        $reset = $this->lastEmail();
        $this->assertSame($address, $reset->getTo()[0]->getAddress(), 'The reset goes to the account\'s current address.');
        $this->assertSame(1, preg_match('~/reset-password#token=([0-9a-f]{64})~', (string) $reset->getTextBody(), $matches));

        $redeemed = $this->requestFrom(self::IP, 'POST', '/api/auth/password/reset', content: ['token' => $matches[1], 'password' => 'owner-password-1']);

        $this->assertJsonResponse($redeemed, 200, 'data');
        $userId = $registrant->getId()->toString();
        $this->assertSame(
            ['email' => $address, 'verified' => true, 'totp_secret_ciphertext' => null, 'totp_last_used_step' => null, 'pending_email' => null],
            $this->connection()->fetchAssociative('SELECT email, email_verified_at IS NOT NULL AS verified, totp_secret_ciphertext, totp_last_used_step, pending_email FROM users WHERE id = ?', [$userId]),
        );
        $this->assertSame([], $this->passkeys()->forUser($registrant->getId()));
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM oauth_access_tokens WHERE user_id = ? AND revoked = FALSE', [$userId]));
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM email_verification_tokens WHERE user_id = ?', [$userId]), 'The pending change\'s link no longer works.');
        $this->assertSame(200, $this->login($address, 'owner-password-1')->getStatusCode(), 'The owner signs in with the password alone.');
    }

    /**
     * Someone registers under an owner's address, and the owner opens the emailed link while
     * signed out. Nothing is verified and the link stays usable; the owner's password reset
     * then claims the account.
     */
    public function testALinkOpenedSignedOutVerifiesNothingAndTheOwnersResetClaimsTheAccount(): void
    {
        FirstPartyOAuthClient::entity(static::getContainer(), $this->entityManager);
        $address = 'registered-' . bin2hex(random_bytes(4)) . '@baander.app';
        $this->assertJsonResponse($this->requestFrom(self::IP, 'POST', '/api/auth/register', content: ['name' => 'Registrant', 'email' => $address, 'password' => 'registrant-password-1']), 202);
        $this->assertSame(1, preg_match('~/verify-email#token=([0-9a-f]{64})~', (string) $this->lastEmail()->getTextBody(), $link));
        $userId = $this->connection()->fetchOne('SELECT id FROM users WHERE email = ?', [$address]);
        $this->assertIsString($userId);

        $opened = $this->requestFrom(self::IP, 'POST', '/api/auth/email/verify', content: ['token' => $link[1]]);

        $this->assertSame(401, $opened->getStatusCode(), (string) $opened->getContent());
        $this->assertSame(
            ['verified' => false, 'tokens' => 1],
            $this->connection()->fetchAssociative('SELECT email_verified_at IS NOT NULL AS verified, (SELECT count(*) FROM email_verification_tokens WHERE user_id = users.id) AS tokens FROM users WHERE id = ?', [$userId]),
            'The address stays unverified and the link stays usable.',
        );

        $this->assertJsonResponse($this->requestFrom(self::IP, 'POST', '/api/auth/password/reset-request', content: ['email' => $address]), 200);
        $this->assertSame(1, preg_match('~/reset-password#token=([0-9a-f]{64})~', (string) $this->lastEmail()->getTextBody(), $reset));
        $this->assertJsonResponse($this->requestFrom(self::IP, 'POST', '/api/auth/password/reset', content: ['token' => $reset[1], 'password' => 'owner-password-1']), 200, 'data');

        $this->assertTrue($this->connection()->fetchOne('SELECT email_verified_at IS NOT NULL FROM users WHERE id = ?', [$userId]), 'The reset verifies the owner\'s address.');
        $this->assertSame(200, $this->login($address, 'owner-password-1')->getStatusCode(), 'The owner signs in with the new password.');
    }

    public function testAResetToAVerifiedAddressKeepsTheAuthenticators(): void
    {
        $user = $this->createTestUser(verified: true);
        $this->enrolTotp($user);
        $this->passkeys()->save(Passkey::create(new Uuid(), 'Own key', bin2hex(random_bytes(16)), [], 0), $user->getId());

        $this->assertJsonResponse($this->requestFrom(self::IP, 'POST', '/api/auth/password/reset-request', content: ['email' => $user->getEmail()]), 200);
        $this->assertSame(1, preg_match('~/reset-password#token=([0-9a-f]{64})~', (string) $this->lastEmail()->getTextBody(), $matches));
        $this->assertJsonResponse($this->requestFrom(self::IP, 'POST', '/api/auth/password/reset', content: ['token' => $matches[1], 'password' => 'owner-password-1']), 200);

        $this->entityManager->clear();
        $this->assertTrue($this->userRepository->findByUuid($user->getId())?->hasTotp());
        $this->assertCount(1, $this->passkeys()->forUser($user->getId()));
    }

    private function login(string $email, string $password): Response
    {
        $key = new SignedDpopProof();
        $challenge = $this->postLogin($email, $password, $key->create('POST', self::LOGIN_URI, 'no-access-token'));
        $nonce = $challenge->headers->get('DPoP-Nonce');
        self::assertIsString($nonce, (string) $challenge->getContent());

        return $this->postLogin($email, $password, $key->createWithNonce('POST', self::LOGIN_URI, $nonce));
    }

    private function postLogin(string $email, string $password, string $proof): Response
    {
        $this->trackLimiter('auth_login_email_failures', $email);
        $this->client->request('POST', '/api/auth/login', [], [], [
            'REMOTE_ADDR' => self::IP,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_DPOP' => $proof,
        ], json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    private function lastEmail(): Email
    {
        $email = array_last($this->getMailerMessages());
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function passkeys(): PasskeyRepositoryInterface
    {
        $repository = static::getContainer()->get(PasskeyRepositoryInterface::class);
        self::assertInstanceOf(PasskeyRepositoryInterface::class, $repository);

        return $repository;
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->entityManager->getConnection();
    }
}
