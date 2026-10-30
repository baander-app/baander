<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveUpdates;

use App\Auth\Application\DTO\CurrentUserAuthority;
use App\Auth\Application\Port\CurrentUserAuthorityReader;
use App\Auth\Infrastructure\LiveUpdates\DoctrineCurrentUserAuthorityReader;
use App\Library\Infrastructure\LiveUpdates\DoctrineLibraryAudienceReader;
use App\Playlist\Infrastructure\LiveUpdates\DoctrinePlaylistAudienceReader;
use App\Shared\Application\LiveUpdates\LiveAccessDenied;
use App\Shared\Application\LiveUpdates\LiveFamily;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\LiveUpdates\DoctrineLiveRevisionStore;
use App\Shared\Infrastructure\LiveUpdates\DoctrineLiveSnapshotReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

final class LiveAudienceTest extends TestCase
{
    private Connection $reader;
    private Connection $writer;
    private string $schema;
    private Uuid $principal;
    private string $other;
    private string $library;
    private string $playlist;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->reader = DriverManager::getConnection($params);
        $this->writer = DriverManager::getConnection($params);
        $this->schema = 'live_audience_' . bin2hex(random_bytes(8));
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->reader, $this->writer] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
            $connection->executeStatement("SET TIME ZONE 'UTC'");
        }
        foreach ([
            'CREATE TABLE users (id uuid PRIMARY KEY, roles jsonb NOT NULL, disabled boolean NOT NULL DEFAULT false)',
            'CREATE TABLE libraries (id uuid PRIMARY KEY)',
            'CREATE TABLE user_library_access (user_id uuid NOT NULL, library_id uuid NOT NULL, PRIMARY KEY(user_id, library_id))',
            'CREATE TABLE playlists (id uuid PRIMARY KEY, user_id uuid NOT NULL, is_public boolean NOT NULL DEFAULT false, is_collaborative boolean NOT NULL DEFAULT false)',
            'CREATE TABLE playlist_collaborators (playlist_id uuid NOT NULL, user_id uuid NOT NULL, PRIMARY KEY(playlist_id, user_id))',
            'CREATE TABLE query_scope_revision (family text NOT NULL, scope_kind text NOT NULL, scope_id text NOT NULL, revision bigint NOT NULL, PRIMARY KEY(family, scope_kind, scope_id))',
            'CREATE TABLE query_change_inbox (created_at timestamptz NOT NULL DEFAULT clock_timestamp())',
        ] as $sql) {
            $this->writer->executeStatement($sql);
        }
        $this->principal = new Uuid();
        $this->other = (new Uuid())->toString();
        $this->library = (new Uuid())->toString();
        $this->playlist = (new Uuid())->toString();
        foreach ([$this->principal->toString(), $this->other] as $id) {
            $this->writer->insert('users', ['id' => $id, 'roles' => '["ROLE_USER"]']);
        }
        $this->writer->insert('libraries', ['id' => $this->library]);
        $this->writer->insert('user_library_access', ['user_id' => $this->principal->toString(), 'library_id' => $this->library]);
        $this->writer->executeStatement('INSERT INTO playlists (id, user_id, is_public) VALUES (?, ?, true)', [$this->playlist, $this->other]);
        $this->writer->insert('playlist_collaborators', ['playlist_id' => $this->playlist, 'user_id' => $this->principal->toString()]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->writer)) {
            return;
        }
        if ($this->reader->isTransactionActive()) {
            $this->reader->rollBack();
        }
        $this->reader->close();
        $this->writer->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        $this->writer->close();
    }

    public function testOnlyCurrentAuthorizedScopesAreObserved(): void
    {
        $allowed = ['catalog:library:' . $this->library, 'catalog:playlist:' . $this->playlist, 'notifications:user:' . $this->principal->toString(), 'radio:global:*'];
        foreach ([...$allowed, 'notifications:user:' . $this->other, 'notifications:library:' . $this->library, 'activity:playlist:' . $this->playlist, 'admin-users:admin:*'] as $key) {
            $this->revision($key, 1);
        }
        $snapshot = $this->snapshots()->read($this->principal);
        self::assertEqualsCanonicalizing($allowed, array_keys($snapshot->revisions));
        self::assertNotContains(LiveFamily::AdminUsers, $snapshot->families);
        self::assertNotContains(LiveFamily::DeploymentDefinitions, $snapshot->families);
        self::assertSame(0, $this->reader->getTransactionNestingLevel());
    }

    public function testOwnersReadPrivatePlaylistsWithoutCollaboration(): void
    {
        $owned = (new Uuid())->toString();
        $this->writer->insert('playlists', ['id' => $owned, 'user_id' => $this->principal->toString()]);
        self::assertEqualsCanonicalizing([$owned, $this->playlist], (new DoctrinePlaylistAudienceReader($this->reader))->readablePlaylistIds($this->principal, false));
    }

    public function testRevocationChangesFingerprintBeforeInboxProjection(): void
    {
        $before = $this->snapshots()->read($this->principal);
        $this->writer->executeStatement('DELETE FROM user_library_access');
        $this->writer->executeStatement('DELETE FROM playlist_collaborators');
        $after = $this->snapshots()->read($this->principal);
        self::assertNotSame($before->authorizationFingerprint, $after->authorizationFingerprint);
        self::assertSame([], $after->revisions);
        self::assertSame([], (new DoctrinePlaylistAudienceReader($this->reader))->readablePlaylistIds($this->principal, false));
    }

    public function testAdministratorsSeeAllResourcesAndRoleLossRemovesAdminFamilies(): void
    {
        $this->writer->executeStatement('DELETE FROM user_library_access');
        $this->writer->executeStatement('DELETE FROM playlist_collaborators');
        $this->writer->executeStatement('UPDATE users SET roles = ? WHERE id = ?', ['["ROLE_SUPER_ADMIN"]', $this->principal->toString()]);
        $this->revision('admin-users:admin:*', 3);
        $this->revision('catalog:library:' . $this->library, 2);
        $this->revision('playlists:playlist:' . $this->playlist, 4);
        $admin = $this->snapshots()->read($this->principal);
        self::assertCount(3, $admin->revisions);
        self::assertContains(LiveFamily::AdminUsers, $admin->families);
        self::assertContains(LiveFamily::DeploymentDefinitions, $admin->families);
        $this->writer->executeStatement('UPDATE users SET roles = ? WHERE id = ?', ['["ROLE_USER"]', $this->principal->toString()]);
        $ordinary = $this->snapshots()->read($this->principal);
        self::assertSame([], $ordinary->revisions);
        self::assertNotSame($admin->authorizationFingerprint, $ordinary->authorizationFingerprint);
        self::assertNotContains(LiveFamily::AdminUsers, $ordinary->families);
    }

    public function testMembershipsAndRevisionsShareOneRepeatableReadSnapshot(): void
    {
        $key = 'catalog:library:' . $this->library;
        $this->revision($key, 1);
        $authority = new class (new DoctrineCurrentUserAuthorityReader($this->reader), $this->writer) implements CurrentUserAuthorityReader {
            public function __construct(private CurrentUserAuthorityReader $inner, private Connection $writer) {}
            public function read(Uuid $principal): ?CurrentUserAuthority
            {
                $authority = $this->inner->read($principal);
                $this->writer->executeStatement('DELETE FROM user_library_access');
                $this->writer->executeStatement('UPDATE query_scope_revision SET revision = 2');

                return $authority;
            }
        };
        $snapshot = $this->snapshots($authority)->read($this->principal);
        self::assertSame('1', $snapshot->revisions[$key]);
        $next = $this->snapshots()->read($this->principal);
        self::assertArrayNotHasKey($key, $next->revisions);
        self::assertNotSame($snapshot->authorizationFingerprint, $next->authorizationFingerprint);
    }

    public function testDisabledAndDeletedPrincipalsFailClosedAndReleaseTransaction(): void
    {
        $this->writer->executeStatement('UPDATE users SET disabled = true WHERE id = ?', [$this->principal->toString()]);
        foreach ([false, true] as $deleted) {
            if ($deleted) {
                $this->writer->executeStatement('DELETE FROM users WHERE id = ?', [$this->principal->toString()]);
            }
            try {
                $this->snapshots()->read($this->principal);
                self::fail('Unavailable principal was accepted.');
            } catch (LiveAccessDenied) {
                self::assertSame(0, $this->reader->getTransactionNestingLevel());
            }
        }
    }

    public function testCanonicalRolesDoNotCauseSpuriousPermissionReset(): void
    {
        $this->writer->executeStatement('UPDATE users SET roles = ? WHERE id = ?', ['["ROLE_USER", "ROLE_ADMIN"]', $this->principal->toString()]);
        $before = $this->snapshots()->read($this->principal);
        $this->writer->executeStatement('UPDATE users SET roles = ? WHERE id = ?', ['["ROLE_ADMIN", "ROLE_USER", "ROLE_USER"]', $this->principal->toString()]);
        self::assertSame($before->authorizationFingerprint, $this->snapshots()->read($this->principal)->authorizationFingerprint);
    }

    public function testTimedOutAuthorityReadFailsClosedAndReleasesConnection(): void
    {
        $authority = new class ($this->reader) implements CurrentUserAuthorityReader {
            public function __construct(private Connection $connection) {}
            public function read(Uuid $principal): CurrentUserAuthority
            {
                $this->connection->executeQuery('SELECT pg_sleep(0.6)');

                return new CurrentUserAuthority(['ROLE_USER'], false);
            }
        };
        try {
            $this->snapshots($authority)->read($this->principal);
            self::fail('Timed-out authority read produced a snapshot.');
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            self::assertSame('57014', $error->getSQLState());
        }
        self::assertSame(0, $this->reader->getTransactionNestingLevel());
        self::assertSame(1, $this->reader->fetchOne('SELECT 1'));
    }

    public function testTransactionDeadlineDiscardsTerminatedConnection(): void
    {
        $authority = new class ($this->reader) implements CurrentUserAuthorityReader {
            public function __construct(private Connection $connection) {}
            public function read(Uuid $principal): CurrentUserAuthority
            {
                for ($index = 0; $index < 4; ++$index) {
                    $this->connection->executeQuery('SELECT pg_sleep(0.35)');
                }

                return new CurrentUserAuthority(['ROLE_USER'], false);
            }
        };
        try {
            $this->snapshots($authority)->read($this->principal);
            self::fail('Expired transaction produced a snapshot.');
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            self::assertStringContainsString('transaction timeout', $error->getMessage());
        }
        self::assertFalse($this->reader->isConnected());
        self::assertSame(0, $this->reader->getTransactionNestingLevel());
        self::assertSame(1, $this->reader->fetchOne('SELECT 1'));
    }

    private function snapshots(?CurrentUserAuthorityReader $authority = null): DoctrineLiveSnapshotReader
    {
        return new DoctrineLiveSnapshotReader($this->reader, $authority ?? new DoctrineCurrentUserAuthorityReader($this->reader), new DoctrineLibraryAudienceReader($this->reader), new DoctrinePlaylistAudienceReader($this->reader), new DoctrineLiveRevisionStore($this->reader));
    }

    private function revision(string $key, int $revision): void
    {
        [$family, $kind, $id] = explode(':', $key, 3);
        $this->writer->insert('query_scope_revision', ['family' => $family, 'scope_kind' => $kind, 'scope_id' => $id, 'revision' => $revision]);
    }
}
