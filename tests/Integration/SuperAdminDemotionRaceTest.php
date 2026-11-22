<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\Auth\OAuthRevocationRace;
use App\Tests\Fixtures\Auth\SuperAdminDemotionRace;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Two enabled super admins who demote each other at the same moment leave one of them a super
 * admin: the changes that can take an enabled super admin away serialize on an advisory lock, and
 * the second one counts the super admins after the first committed.
 *
 * The test process holds one demotion's transaction open after its writes while a child process
 * runs the other demotion over its own PostgreSQL session; pg_stat_activity shows when the child
 * waits on the advisory lock. SetUserRolesHandler and the production user repository run over the
 * production mapping in a disposable schema.
 */
final class SuperAdminDemotionRaceTest extends TestCase
{
    private const string ROOT = 'root@baander.app';
    private const string SECOND = 'second@baander.app';
    private const array SUPER_ADMIN = ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    private Connection $writer;
    private Connection $observer;
    private EntityManager $manager;
    private string $schema;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }

        $this->schema = 'super_admin_race_' . (random_bytes(8) |> bin2hex(...));
        $this->observer = OAuthRevocationRace::connect('public');
        self::assertStringStartsWith('18.', (string) $this->observer->fetchOne('SHOW server_version'));
        // The count after the lock sees the other demotion's commit only under READ COMMITTED.
        self::assertSame('read committed', $this->observer->fetchOne('SHOW default_transaction_isolation'));
        $this->observer->executeStatement('CREATE EXTENSION IF NOT EXISTS citext');
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        $this->writer = OAuthRevocationRace::connect($this->schema);
        $this->manager = OAuthRevocationRace::entityManager($this->writer);
        (new SchemaTool($this->manager))->createSchema([$this->manager->getClassMetadata(UserEntity::class)]);

        foreach ([self::ROOT, self::SECOND] as $email) {
            $this->manager->persist(new UserEntity(new PublicId(), 'Super Admin', $email, 'hashed', roles: self::SUPER_ADMIN));
        }
        $this->manager->flush();
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

    public function testOfTwoSuperAdminsDemotingEachOtherAtOnceOneSucceedsAndTheOtherIsRefused(): void
    {
        $child = null;
        SuperAdminDemotionRace::setRoles($this->manager, self::SECOND, ['ROLE_USER', 'ROLE_ADMIN'], function () use (&$child): void {
            // The first demotion holds the lock and has written, but not committed.
            $child = SuperAdminDemotionRace::start($this->schema, self::ROOT, ['ROLE_USER', 'ROLE_ADMIN']);
            $this->awaitAdvisoryLockWait($child);
        });
        self::assertNotNull($child);

        self::assertSame(['refused' => 'last_super_admin'], $this->finish($child));
        self::assertSame(self::SUPER_ADMIN, $this->storedRoles(self::ROOT));
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $this->storedRoles(self::SECOND));
    }

    public function testTheLockRequiresAnOpenTransaction(): void
    {
        $this->expectException(\LogicException::class);

        SuperAdminDemotionRace::users($this->manager)->lockSuperAdminRemovals();
    }

    /** @param array{process: resource, output: resource, application: string} $child */
    private function awaitAdvisoryLockWait(array $child): void
    {
        $deadline = microtime(true) + 5;
        while (true) {
            $this->observer->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            $waiting = $this->observer->fetchOne(
                'SELECT 1 FROM pg_stat_activity activity JOIN pg_locks waiting ON waiting.pid = activity.pid AND NOT waiting.granted'
                . " WHERE activity.application_name = ? AND waiting.locktype = 'advisory'",
                [$child['application']],
            );
            if ($waiting !== false) {
                return;
            }
            if (microtime(true) > $deadline || !proc_get_status($child['process'])['running']) {
                rewind($child['output']);
                self::fail('The second demotion must wait on the advisory lock: ' . stream_get_contents($child['output']));
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
                self::fail('The second demotion did not finish after the lock was released.');
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

    /** @return list<string> */
    private function storedRoles(string $email): array
    {
        $roles = json_decode((string) $this->observer->fetchOne('SELECT roles FROM users WHERE email = ?', [$email]), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($roles);

        return $roles;
    }
}
