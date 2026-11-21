<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Session\Infrastructure\Doctrine\Entity\DeviceEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Session\PlaybackPersistenceFixture;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/Session/playback-concurrent-transfer.php';
require_once dirname(__DIR__) . '/Fixtures/BrowserDevices/device-fixture.php';

final class BrowserDeviceFixtureTest extends TestCase
{
    private string $schema;
    private Connection $connection;
    private EntityManager $manager;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->schema = 'browser_devices_' . bin2hex(random_bytes(8));
        $this->connection = PlaybackPersistenceFixture::connect('public');
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->connection->quoteIdentifier($this->schema));
        $this->connection->executeStatement('SET search_path TO ' . $this->connection->quoteIdentifier($this->schema) . ', public');
        FinalSchemaBootstrap::bootstrap($this->connection);
        $this->manager = PlaybackPersistenceFixture::manager($this->connection);
    }

    protected function tearDown(): void
    {
        if (!isset($this->manager)) {
            return;
        }
        $this->manager->close();
        $this->connection->executeStatement('DROP SCHEMA ' . $this->connection->quoteIdentifier($this->schema) . ' CASCADE');
        $this->connection->close();
    }

    public function testOnlyOwnedAdminDevicesAreDeletedAcrossBatchesAndCleanupIsIdempotent(): void
    {
        $admin = $this->user('admin@baander.test');
        $other = $this->user('other-browser-owner@baander.app');
        $tokens = array_map(static fn (): string => Uuid::v4()->toString(), range(1, 513));
        foreach ($tokens as $token) {
            $this->device($admin, $token);
        }
        $sentinel = Uuid::v4()->toString();
        $this->device($admin, $sentinel);
        $this->device($other, $tokens[0]);
        $this->manager->flush();
        $destination = new Uuid()->toString();
        $this->connection->executeStatement(
            'INSERT INTO playback_destinations (id, public_id, user_id, device_id, endpoint_id, name, created_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
            [$destination, new PublicId()->toString(), $admin->getId()->toString(), $tokens[0], new Uuid()->toString(), 'Browser destination'],
        );
        $fixture = new \BrowserDeviceFixture($this->connection);

        self::assertSame(513, $fixture->cleanup([...$tokens, $tokens[0], Uuid::v4()->toString()]));
        self::assertSame(0, $fixture->cleanup($tokens));
        self::assertSame(0, $fixture->cleanup([]));
        self::assertSame([
            ['user_id' => $admin->getId()->toString(), 'device_id' => $sentinel],
            ['user_id' => $other->getId()->toString(), 'device_id' => $tokens[0]],
        ], $this->connection->fetchAllAssociative('SELECT user_id, device_id FROM devices ORDER BY user_id'));
        self::assertSame($destination, $this->connection->fetchOne('SELECT id FROM playback_destinations'));
    }

    public function testEveryTokenIsValidatedBeforeDeletingAnyRegistration(): void
    {
        $admin = $this->user('admin@baander.test');
        $token = Uuid::v4()->toString();
        $this->device($admin, $token);
        $this->manager->flush();
        $fixture = new \BrowserDeviceFixture($this->connection);
        foreach (['invalid', '', null, 1, [], '00000000-0000-0000-0000-00000000000x'] as $invalid) {
            try {
                $fixture->cleanup([$token, $invalid]);
                self::fail('Malformed ownership must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM devices'));
            }
        }
    }

    public function testMissingSeededAdminIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('seeded administrator');
        new \BrowserDeviceFixture($this->connection)->cleanup([Uuid::v4()->toString()]);
    }

    public function testDirectCliExecutionRequiresExplicitOptInBeforeConnecting(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__) . '/Fixtures/BrowserDevices/device-fixture.php'], env: [
            'BAANDER_BROWSER_DEVICE_CLEANUP' => false,
            'DATABASE_URL' => 'invalid-database-url',
        ]);
        $process->setInput('{"deviceIds":[]}');
        $process->run();

        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString('BAANDER_BROWSER_DEVICE_CLEANUP=1', $process->getErrorOutput());
    }

    private function user(string $email): UserEntity
    {
        $user = new UserEntity(new PublicId(), 'Browser owner', $email, 'unused-password');
        $this->manager->persist($user);
        return $user;
    }

    private function device(UserEntity $owner, string $token): void
    {
        $this->manager->persist(new DeviceEntity($owner->getId(), Uuid::fromString($token)));
    }
}
