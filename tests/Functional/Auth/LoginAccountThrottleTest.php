<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Tests\Fixtures\Auth\FirstPartyOAuthClient;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Symfony\Component\HttpFoundation\Response;

/**
 * Failed password logins are limited per account across client addresses. The test
 * environment raises every limit to 10000, so each test spends the bucket down to the
 * failures it then makes over HTTP.
 */
final class LoginAccountThrottleTest extends RateLimitTestCase
{
    private const string URI = 'http://localhost/api/auth/login';
    private const string LIMITER = 'auth_login_email_failures';

    public function testFailuresFromTwentyAddressesLockTheAccountForEveryAddress(): void
    {
        $user = $this->createTestUser(password: 'right-password-1');
        $this->spendAllBut(20, $user->getEmail());

        foreach (range(1, 20) as $host) {
            $failed = $this->login('198.18.10.' . $host, $user->getEmail(), 'wrong-password-' . $host);
            self::assertSame(401, $failed->getStatusCode(), (string) $failed->getContent());
        }

        // A new address with the right password, and a case variant of the address, wait too.
        $this->assertTooManyRequests($this->login('198.18.10.21', $user->getEmail(), 'right-password-1'));
        $this->assertTooManyRequests($this->login('198.18.10.22', strtoupper($user->getEmail()), 'right-password-1'));
    }

    public function testASuccessfulLoginIsNotCharged(): void
    {
        FirstPartyOAuthClient::entity(static::getContainer(), $this->entityManager);
        $user = $this->createTestUser(password: 'right-password-1');
        $this->spendAllBut(1, $user->getEmail());

        $success = $this->login('198.18.10.30', $user->getEmail(), 'right-password-1');
        self::assertSame(200, $success->getStatusCode(), (string) $success->getContent());

        $last = $this->login('198.18.10.31', $user->getEmail(), 'wrong-password');
        self::assertSame(401, $last->getStatusCode(), (string) $last->getContent());
        $this->assertTooManyRequests($this->login('198.18.10.32', $user->getEmail(), 'right-password-1'));
    }

    public function testAnUnknownAddressIsLimitedLikeAKnownOne(): void
    {
        $known = $this->createTestUser()->getEmail();
        $unknown = 'nobody-' . bin2hex(random_bytes(4)) . '@baander.app';
        $this->spendAllBut(1, $known);
        $this->spendAllBut(1, $unknown);

        $knownFailure = $this->login('198.18.10.40', $known, 'wrong-password');
        $unknownFailure = $this->login('198.18.10.40', $unknown, 'wrong-password');
        self::assertSame(401, $unknownFailure->getStatusCode(), (string) $unknownFailure->getContent());
        self::assertSame($knownFailure->getContent(), $unknownFailure->getContent());

        $knownLimited = $this->login('198.18.10.41', $known, 'wrong-password');
        $unknownLimited = $this->login('198.18.10.41', $unknown, 'wrong-password');
        $this->assertTooManyRequests($unknownLimited);
        self::assertSame($knownLimited->getStatusCode(), $unknownLimited->getStatusCode());
        self::assertSame($knownLimited->getContent(), $unknownLimited->getContent());
    }

    private function spendAllBut(int $remaining, string $email): void
    {
        $this->trackLimiter(self::LIMITER, $email);
        self::assertTrue($this->limiter(self::LIMITER)->create($email)->consume(10000 - $remaining)->isAccepted());
    }

    private function login(string $ip, string $email, string $password): Response
    {
        $key = new SignedDpopProof();
        $challenge = $this->post($ip, $email, $password, $key->create('POST', self::URI, 'no-access-token'));
        $nonce = $challenge->headers->get('DPoP-Nonce');
        self::assertIsString($nonce, (string) $challenge->getContent());

        return $this->post($ip, $email, $password, $key->createWithNonce('POST', self::URI, $nonce));
    }

    private function post(string $ip, string $email, string $password, string $proof): Response
    {
        $this->client->request('POST', '/api/auth/login', [], [], [
            'REMOTE_ADDR' => $ip,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_DPOP' => $proof,
        ], json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }
}
