<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;

/** Authorization codes carry their redirect URI, an S256 PKCE challenge and the thumbprint of their DPoP key. */
final class AuthCodePkceMigrationTest extends TestCase
{
    use OwnershipPersistenceHarness;

    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    // RFC 7638 section 3.1.
    private const THUMBPRINT = 'NzbLsXh8uDCcd-6MNwXF4W_7noWXFZAfHkxZsRGC9Xs';

    public function testAnAuthCodeNeedsARedirectUriAnS256ChallengeAndAKeyThumbprint(): void
    {
        self::assertSame([
            'code_challenge' => 'NO',
            'code_challenge_method' => 'NO',
            'dpop_jkt' => 'NO',
            'redirect_uri' => 'NO',
        ], $this->columns());
        self::assertSame(
            ['chk_oauth_auth_codes_code_challenge_method' => "CHECK ((code_challenge_method = 'S256'::text))"],
            $this->checks(),
        );

        $client = $this->createClient();
        $user = $this->createUser();
        $this->insertAuthCode($client, $user);
        self::assertSame(1, $this->countOwnedRows('oauth_auth_codes', 'user_id', $user));

        // DBAL 4.4 has no CHECK violation exception class; 23514 is check_violation, 23502 not_null_violation.
        $this->assertRejected('23514', fn () => $this->insertAuthCode($client, $user, ['code_challenge_method' => 'plain']));
        foreach (['redirect_uri', 'code_challenge', 'code_challenge_method', 'dpop_jkt'] as $column) {
            $this->assertRejected('23502', fn () => $this->insertAuthCode($client, $user, [$column => null]));
        }
    }

    /** Runs $write in a savepoint and expects PostgreSQL to reject it with $sqlState. */
    private function assertRejected(string $sqlState, \Closure $write): void
    {
        $connection = $this->manager->getConnection();
        $connection->beginTransaction();
        try {
            $write();
            self::fail('Expected SQLSTATE ' . $sqlState);
        } catch (DriverException $exception) {
            self::assertSame($sqlState, $exception->getSQLState());
        } finally {
            $connection->rollBack();
        }
    }

    /** @param array<string, string|null> $overrides */
    private function insertAuthCode(Uuid $client, Uuid $user, array $overrides = []): void
    {
        $this->manager->getConnection()->insert('oauth_auth_codes', [
            'id' => Uuid::generate()->toString(),
            'code_id' => bin2hex(random_bytes(16)),
            'client_id' => $client->toString(),
            'user_id' => $user->toString(),
            'redirect_uri' => 'https://app.baander.app/callback',
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'dpop_jkt' => self::THUMBPRINT,
            'created_at' => '2026-10-07 12:00:00+00',
            'updated_at' => '2026-10-07 12:00:00+00',
            ...$overrides,
        ]);
    }

    private function createClient(): Uuid
    {
        $id = Uuid::generate();
        $this->manager->getConnection()->insert('oauth_clients', [
            'id' => $id->toString(),
            'public_id' => (new PublicId())->toString(),
            'name' => 'PKCE client',
            'redirect' => '["https://app.baander.app/callback"]',
            'created_at' => '2026-10-07 12:00:00+00',
            'updated_at' => '2026-10-07 12:00:00+00',
        ]);

        return $id;
    }

    /** @return array<string, string> column => is_nullable, for the redirect URI, PKCE and key-binding columns */
    private function columns(): array
    {
        return $this->manager->getConnection()->fetchAllKeyValue(
            "SELECT column_name, is_nullable FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = 'oauth_auth_codes'
                AND column_name IN ('redirect_uri', 'code_challenge', 'code_challenge_method', 'dpop_jkt')
              ORDER BY 1",
        );
    }

    /** @return array<string, string> CHECK constraint name => definition */
    private function checks(): array
    {
        return $this->manager->getConnection()->fetchAllKeyValue(
            "SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint
              WHERE contype = 'c' AND conrelid = 'oauth_auth_codes'::regclass ORDER BY 1",
        );
    }
}
