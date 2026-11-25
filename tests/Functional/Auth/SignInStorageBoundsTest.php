<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Domain\Model\LoginBlock;
use App\Auth\Domain\Model\LoginBlockState;
use App\Auth\Domain\Repository\LoginBlockRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Symfony\Component\HttpFoundation\Response;

/**
 * What one sign-in request can make the server decode and store. Every request here carries an
 * accepted DPoP proof and fills the honeypot, so a body that got past the size cap would be
 * stored as a login block.
 */
final class SignInStorageBoundsTest extends RateLimitTestCase
{
    private const string URI = 'http://localhost/api/auth/login';

    /** Refused before RateLimitListener reads the address out of it and before the authenticator decodes it. */
    public function testABodyOver64KiBIsRefusedUndecodedAndStoresNothing(): void
    {
        $ip = '198.18.16.1';
        $email = $this->email();
        $this->trackLimiter('auth_login_ip_email', $ip . '|' . $email);
        $before = $this->blockCount();

        $response = $this->honeypot($ip, $this->body($email, 2_000_000));

        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($before, $this->blockCount());
        self::assertSame(10000, $this->limiter('auth_login_ip_email')->create($ip . '|' . $email)->consume(0)->getRemainingTokens());
    }

    public function testABodyOfExactly64KiBIsDecodedAndItsFieldStoredCut(): void
    {
        $ip = '198.18.16.2';
        $email = $this->email();
        $this->trackLimiter('auth_login_ip_email', $ip . '|' . $email);
        $body = $this->body($email, 65536);
        self::assertSame(65536, strlen($body));

        $response = $this->honeypot($ip, $body);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(9999, $this->limiter('auth_login_ip_email')->create($ip . '|' . $email)->consume(0)->getRemainingTokens());
        self::assertSame(str_repeat('b', 255), $this->storedBlock($ip)['field_value']);
    }

    public function testA300CharacterHoneypotFieldIsStoredAs255(): void
    {
        $ip = '198.18.16.3';
        $field = str_repeat('0123456789', 30);

        $response = $this->honeypot($ip, json_encode(['email' => $this->email(), 'password' => 'secret', 'username' => $field], JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(substr($field, 0, 255), $this->storedBlock($ip)['field_value']);
    }

    /** PostgreSQL text refuses a NUL byte and invalid UTF-8; both used to fail the insert with a 500. */
    public function testANulByteAndInvalidUtf8AreStoredScrubbedAndCutBetweenCharacters(): void
    {
        $ip = '198.18.16.4';
        $email = $this->email();
        $body = json_encode(['email' => $email, 'password' => 'secret', 'username' => "\0" . str_repeat('ø', 300)], JSON_THROW_ON_ERROR);

        $response = $this->honeypot($ip, $body, ['HTTP_USER_AGENT' => "Bot\xFF/1.0 " . str_repeat('é', 300)]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode(), (string) $response->getContent());
        $stored = $this->storedBlock($ip);
        self::assertSame('?' . str_repeat('ø', 254), $stored['field_value']);
        self::assertSame('Bot?/1.0 ' . str_repeat('é', 246), $stored['user_agent']);
        self::assertSame($email, $stored['email']);
    }

    public function testStoringABlockDeletesBlocksOlderThanThirtyDays(): void
    {
        $now = new \DateTimeImmutable();
        $expired = $this->recordedAt('198.18.16.5', $now->modify('-31 days'));
        $kept = $this->recordedAt('198.18.16.6', $now->modify('-29 days'));

        $this->blocks()->save(LoginBlock::create('198.18.16.7', 'bot@baander.app', 'filled', 'curl/8'));

        $stored = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT public_id FROM login_blocks WHERE public_id IN (?, ?)',
            [$expired->getPublicId()->toString(), $kept->getPublicId()->toString()],
        );
        self::assertSame([$kept->getPublicId()->toString()], $stored);
    }

    /** A sign-in body that fills the honeypot with `b` characters, padded to exactly $bytes. */
    private function body(string $email, int $bytes): string
    {
        $empty = json_encode(['email' => $email, 'password' => 'secret', 'username' => ''], JSON_THROW_ON_ERROR);

        return json_encode(['email' => $email, 'password' => 'secret', 'username' => str_repeat('b', $bytes - strlen($empty))], JSON_THROW_ON_ERROR);
    }

    /**
     * Sends $body with an accepted proof: a first request with an empty object fetches the nonce
     * without naming an address.
     *
     * @param array<string, string> $server
     */
    private function honeypot(string $ip, string $body, array $server = []): Response
    {
        $this->trackLimiter('auth_login_ip', $ip);
        $key = new SignedDpopProof();
        $challenge = $this->post($ip, '{}', $key->create('POST', self::URI, 'no-access-token'), $server);
        $nonce = $challenge->headers->get('DPoP-Nonce');
        self::assertIsString($nonce, (string) $challenge->getContent());

        return $this->post($ip, $body, $key->createWithNonce('POST', self::URI, $nonce), $server);
    }

    /** @param array<string, string> $server */
    private function post(string $ip, string $body, string $proof, array $server): Response
    {
        $this->client->request('POST', '/api/auth/login', [], [], [
            'REMOTE_ADDR' => $ip,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_DPOP' => $proof,
            ...$server,
        ], $body);

        return $this->client->getResponse();
    }

    /** @return array<string, string> */
    private function storedBlock(string $ip): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT email, field_value, user_agent FROM login_blocks WHERE ip_address = ?',
            [$ip],
        );
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function recordedAt(string $ip, \DateTimeImmutable $createdAt): LoginBlock
    {
        $block = LoginBlock::reconstitute(new LoginBlockState(
            id: Uuid::v7(),
            publicId: new PublicId(),
            ipAddress: $ip,
            email: 'bot@baander.app',
            fieldValue: 'filled',
            userAgent: 'curl/8',
            createdAt: $createdAt,
        ));
        $this->blocks()->save($block);

        return $block;
    }

    private function blocks(): LoginBlockRepositoryInterface
    {
        $blocks = static::getContainer()->get(LoginBlockRepositoryInterface::class);
        self::assertInstanceOf(LoginBlockRepositoryInterface::class, $blocks);

        return $blocks;
    }

    private function blockCount(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM login_blocks');
    }

    private function email(): string
    {
        return 'bounds-' . bin2hex(random_bytes(4)) . '@baander.app';
    }
}
