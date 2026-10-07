<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\RefreshTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\TokenMetadataEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Auth\Infrastructure\Repository\OAuth\ClientRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\OAuthRevocationRace;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Token issuance and client revocation serialize on the client's row (lockActiveClientForIssuance).
 *
 * The test process holds one side's transaction open while a child process runs the other side
 * over its own PostgreSQL session; pg_stat_activity shows when the child waits on the row lock.
 * Production repositories, TokenPairIssuer and RevokeClientHandler run over the production
 * mappings in a disposable schema.
 */
final class OAuthClientRevocationRaceTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private EntityManager $manager;
    private string $schema;
    private Uuid $userId;
    private PublicId $userPublicId;
    private Client $client;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }

        $this->schema = 'oauth_revocation_race_' . bin2hex(random_bytes(8));
        $this->observer = OAuthRevocationRace::connect('public');
        self::assertStringStartsWith('18.', (string) $this->observer->fetchOne('SHOW server_version'));
        $this->observer->executeStatement('CREATE EXTENSION IF NOT EXISTS citext');
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        $this->writer = OAuthRevocationRace::connect($this->schema);
        $this->manager = OAuthRevocationRace::entityManager($this->writer);
        (new SchemaTool($this->manager))->createSchema(array_map(
            $this->manager->getClassMetadata(...),
            [UserEntity::class, ClientEntity::class, AccessTokenEntity::class, RefreshTokenEntity::class, TokenMetadataEntity::class],
        ));

        $this->userId = Uuid::generate();
        $this->userPublicId = new PublicId();
        $this->manager->persist(new UserEntity($this->userPublicId, 'Race', OAuthRevocationRace::USER_EMAIL, 'hashed', '', $this->userId));
        $this->manager->flush();
        $this->client = Client::createPersonalAccess('Race client', $this->userId);
        (new ClientRepository($this->manager, new JsonEncoder()))->saveClient($this->client);
        $this->manager->clear();
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            $this->writer->close();
        }
        if (isset($this->observer)) {
            $this->observer->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->observer->close();
        }
    }

    public function testIssuanceWaitsForACommittingRevocationAndThenFailsWithInvalidClient(): void
    {
        $child = null;
        OAuthRevocationRace::revoke($this->manager, $this->userId, $this->client->getPublicId(), function () use (&$child): void {
            // The revocation has updated the client row and its tokens but not committed.
            $child = $this->startChild('issue');
            $this->awaitLockWait($child);
        });
        self::assertNotNull($child);

        self::assertSame(['error' => 'invalid_client', 'status' => 401], $this->finish($child));
        self::assertSame(0, $this->rowCount('oauth_access_tokens'));
        self::assertSame(0, $this->rowCount('oauth_refresh_tokens'));
        self::assertTrue((bool) $this->observer->fetchOne('SELECT revoked FROM oauth_clients WHERE id = ?', [$this->client->getId()->toString()]));
    }

    public function testRevocationWaitsForAnIssuingTransactionAndThenRevokesItsTokens(): void
    {
        $child = null;
        $tokens = OAuthRevocationRace::issue(
            $this->manager,
            $this->client->getId(),
            OAuthRevocationRace::user($this->userId, $this->userPublicId),
            function () use (&$child): void {
                // The issuing transaction holds FOR SHARE on the client row; its tokens follow.
                $child = $this->startChild('revoke');
                $this->awaitLockWait($child);
            },
        );
        self::assertNotNull($child);

        self::assertSame(['revoked' => true], $this->finish($child));
        self::assertSame(1, $this->rowCount('oauth_access_tokens'));
        self::assertSame(1, $this->rowCount('oauth_refresh_tokens'));
        self::assertSame(0, $this->rowCount('oauth_access_tokens', 'NOT revoked'));
        self::assertSame(0, $this->rowCount('oauth_refresh_tokens', 'NOT revoked'));
        self::assertTrue((bool) $this->observer->fetchOne(
            'SELECT revoked FROM oauth_refresh_tokens WHERE token_id = ?',
            [$tokens->getRefreshToken()],
        ), 'The token pair issued while the revocation waited is revoked with the client.');
    }

    public function testTheLockRequiresAnOpenTransaction(): void
    {
        $this->expectException(\LogicException::class);

        (new ClientRepository($this->manager, new JsonEncoder()))->lockActiveClientForIssuance($this->client->getId());
    }

    /** @return array{process: resource, output: resource, application: string} */
    private function startChild(string $operation): array
    {
        $application = 'oauth_race_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        self::assertIsResource($output);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Auth/oauth-revocation-race.php', $this->schema, $application, $operation,
                $this->client->getId()->toString(), $this->client->getPublicId()->toString(), $this->userId->toString(), $this->userPublicId->toString()],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        self::assertIsResource($process);

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /** @param array{process: resource, output: resource, application: string} $child */
    private function awaitLockWait(array $child): void
    {
        $deadline = microtime(true) + 5;
        while (true) {
            $this->observer->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            $waiting = $this->observer->fetchOne(
                "SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'",
                [$child['application']],
            );
            if ($waiting !== false) {
                return;
            }
            if (microtime(true) > $deadline || !proc_get_status($child['process'])['running']) {
                rewind($child['output']);
                self::fail('The child must wait on the client row lock: ' . stream_get_contents($child['output']));
            }
            usleep(1000);
        }
    }

    /**
     * @param array{process: resource, output: resource, application: string} $child
     *
     * @return array<string, mixed>
     */
    private function finish(array $child): array
    {
        $deadline = microtime(true) + 10;
        while (proc_get_status($child['process'])['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($child['process'], 9);
                self::fail('The child did not finish after the lock was released.');
            }
            usleep(1000);
        }
        rewind($child['output']);
        $result = (string) stream_get_contents($child['output']);
        fclose($child['output']);
        self::assertSame(0, proc_close($child['process']), $result);

        $decoded = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function rowCount(string $table, string $condition = 'TRUE'): int
    {
        return (int) $this->observer->fetchOne('SELECT count(*) FROM ' . $table . ' WHERE ' . $condition);
    }
}
