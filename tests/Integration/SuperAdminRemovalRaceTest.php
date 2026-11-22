<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\RefreshTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\Auth\OAuthRevocationRace;
use App\Tests\Fixtures\Auth\SuperAdminRemoval;
use App\Tests\Fixtures\Auth\SuperAdminRemovalRace;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * When one of two enabled super admins demotes the other while the other demotes, disables or
 * deletes the first, one of them stays an enabled super admin: the changes that can take an
 * enabled super admin away serialize on an advisory lock, and the second one counts the super
 * admins after the first committed.
 *
 * The test process holds the demotion's transaction open after its writes while a child process
 * runs the other change over its own PostgreSQL session; pg_stat_activity shows when the child
 * waits on the advisory lock. The production handlers and user repository run over the
 * production mapping in a disposable schema.
 */
final class SuperAdminRemovalRaceTest extends TestCase
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
        // The count after the lock sees the other change's commit only under READ COMMITTED.
        self::assertSame('read committed', $this->observer->fetchOne('SHOW default_transaction_isolation'));
        $this->observer->executeStatement('CREATE EXTENSION IF NOT EXISTS citext');
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        $this->writer = OAuthRevocationRace::connect($this->schema);
        $this->manager = OAuthRevocationRace::entityManager($this->writer);
        // The token tables let a disable that is not refused revoke the user's tokens and report what it stored.
        (new SchemaTool($this->manager))->createSchema(array_map(
            $this->manager->getClassMetadata(...),
            [UserEntity::class, ClientEntity::class, AccessTokenEntity::class, RefreshTokenEntity::class],
        ));

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

    /** @return iterable<string, array{SuperAdminRemoval}> */
    public static function racingRemovals(): iterable
    {
        foreach (SuperAdminRemoval::cases() as $removal) {
            yield $removal->value => [$removal];
        }
    }

    #[DataProvider('racingRemovals')]
    public function testOfTwoSuperAdminsRemovingEachOtherAtOnceOneSucceedsAndTheOtherIsRefused(SuperAdminRemoval $racing): void
    {
        $child = null;
        SuperAdminRemovalRace::remove($this->manager, SuperAdminRemoval::Demotion, self::SECOND, function () use ($racing, &$child): void {
            // The demotion holds the lock and has written, but not committed.
            $child = SuperAdminRemovalRace::start($this->schema, $racing, self::ROOT);
            $this->awaitAdvisoryLockWait($child);
        });
        self::assertNotNull($child);

        self::assertSame(['refused' => 'last_super_admin'], $this->finish($child));
        self::assertSame(['roles' => self::SUPER_ADMIN, 'disabled' => false], $this->stored(self::ROOT));
        self::assertSame(['roles' => SuperAdminRemovalRace::DEMOTED_ROLES, 'disabled' => false], $this->stored(self::SECOND));
    }

    public function testTheLockRequiresAnOpenTransaction(): void
    {
        $this->expectException(\LogicException::class);

        SuperAdminRemovalRace::users($this->manager)->lockSuperAdminRemovals();
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
                self::fail('The racing change must wait on the advisory lock: ' . stream_get_contents($child['output']));
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
                self::fail('The racing change did not finish after the lock was released.');
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

    /** @return array{roles: list<string>, disabled: bool} */
    private function stored(string $email): array
    {
        $row = $this->observer->fetchAssociative('SELECT roles, disabled FROM users WHERE email = ?', [$email]);
        self::assertIsArray($row, $email . ' is still stored.');
        $roles = json_decode((string) $row['roles'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($roles);
        self::assertIsBool($row['disabled']);

        /** @var list<string> $roles */
        return ['roles' => $roles, 'disabled' => $row['disabled']];
    }
}
