<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveUpdates;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommittedCaptureTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->writer = DriverManager::getConnection($params);
        $this->observer = DriverManager::getConnection($params);
        $this->schema = 'live_capture_' . bin2hex(random_bytes(8));
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->writer, $this->observer] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        }
        $files = glob(dirname(__DIR__, 3) . '/migrations/Version*.php');
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            if (basename($file) === 'Version20261030210000.php') {
                $existing = $this->user();
            }
            require_once $file;
            $class = 'DoctrineMigrations\\' . basename($file, '.php');
            $migration = new $class($this->writer, new NullLogger());
            $migration->up(new Schema());
            $this->writer->transactional(static function (Connection $connection) use ($migration): void {
                foreach ($migration->getSql() as $query) {
                    if (preg_match('/\ACREATE EXTENSION\b/i', ltrim($query->getStatement())) !== 1) {
                        $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
                    }
                }
            });
        }
        if (isset($existing)) {
            self::assertSame('Fixture', $this->observer->fetchOne('SELECT name FROM users WHERE id = ?', [$existing]));
            self::assertSame(0, $this->pendingCount(), 'Installing capture preserves existing data without fabricating changes.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->writer)) {
            return;
        }
        foreach ([$this->writer, $this->observer] as $connection) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        $this->writer->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        $this->writer->close();
        $this->observer->close();
    }

    public function testInstalledCaptureMatchesEveryDeclaredSourceAndRelevantColumn(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/Fixtures/LiveUpdates/capture-sources.json'), true, flags: JSON_THROW_ON_ERROR);
        $actual = $this->observer->fetchFirstColumn(<<<'SQL'
            SELECT DISTINCT c.relname FROM pg_trigger t
            JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = :schema AND NOT t.tgisinternal
              AND pg_get_triggerdef(t.oid) LIKE '%query_%'
            ORDER BY c.relname
            SQL, ['schema' => $this->schema]);
        $expected = array_column(array_filter($manifest['sources'], static fn(array $source): bool => $source['classification'] === 'capture'), 'table');
        sort($expected);
        self::assertSame($expected, $actual);
        $definitions = $this->observer->fetchAllAssociative(<<<'SQL'
            SELECT c.relname, pg_get_triggerdef(t.oid) definition FROM pg_trigger t
            JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = :schema AND NOT t.tgisinternal
            SQL, ['schema' => $this->schema]);
        foreach ($manifest['sources'] as $source) {
            if ($source['classification'] !== 'capture') {
                continue;
            }
            $installed = implode(' ', array_column(array_filter($definitions, static fn(array $row): bool => $row['relname'] === $source['table']), 'definition'));
            self::assertStringContainsString('{' . implode(',', $source['relevant_columns']) . '}', $installed, $source['table'] . ' relevant fields drifted from capture review.');
            self::assertStringContainsString('{' . implode(',', $source['families']) . '}', $installed, $source['table'] . ' families drifted from capture review.');
            self::assertStringContainsString('{' . implode(',', $source['scope_routes']) . '}', $installed, $source['table'] . ' scope routes drifted from capture review.');
            foreach ($source['operations'] as $operation) {
                self::assertStringContainsString($operation, $installed, $source['table'] . ' operation lacks capture.');
            }
        }
        self::assertSame('YES', $this->observer->fetchOne("SELECT is_identity FROM information_schema.columns WHERE table_schema = :schema AND table_name = 'query_change_inbox' AND column_name = 'id'", ['schema' => $this->schema]));
        self::assertSame('timestamp with time zone', $this->observer->fetchOne("SELECT data_type FROM information_schema.columns WHERE table_schema = :schema AND table_name = 'query_change_inbox' AND column_name = 'created_at'", ['schema' => $this->schema]));
    }

    public function testCaptureIsInvisibleBeforeCommitAndDisappearsOnRollback(): void
    {
        $user = $this->user();
        $this->clearInbox();
        $this->writer->beginTransaction();
        $this->notification($user);
        self::assertSame(0, $this->pendingCount());
        self::assertGreaterThan(0, (int) $this->writer->fetchOne('SELECT count(*) FROM query_change_inbox'));
        $this->writer->rollBack();
        self::assertSame(0, $this->pendingCount());
        $this->writer->beginTransaction();
        $this->notification($user);
        $this->writer->commit();
        self::assertSame([['family' => 'notifications', 'scope_kind' => 'user', 'scope_id' => $user]], $this->scopes());
    }

    public function testBulkReadChangesCaptureOnlyRecipientsAndNoopsDoNotAppend(): void
    {
        $first = $this->user();
        $second = $this->user();
        for ($i = 0; $i < 30; ++$i) {
            $this->notification($i % 2 === 0 ? $first : $second);
        }
        $this->clearInbox();
        $this->writer->executeStatement('UPDATE notifications SET is_read = true');
        $scopes = $this->scopes();
        self::assertCount(2, $scopes);
        self::assertSame(2, $this->pendingCount(), 'Bulk updates coalesce repeated recipients at statement capture.');
        self::assertEqualsCanonicalizing([$first, $second], array_column($scopes, 'scope_id'));
        $this->clearInbox();
        $this->writer->executeStatement('UPDATE notifications SET is_read = true');
        self::assertSame(0, $this->pendingCount());
    }

    public function testSecretTimestampAndHeartbeatUpdatesDoNotCapture(): void
    {
        $user = $this->user();
        $this->writer->executeStatement("UPDATE users SET totp_secret = 'initial-secret' WHERE id = ?", [$user]);
        $this->clearInbox();
        $this->writer->executeStatement("UPDATE users SET password = 'new-secret', totp_secret = 'another-secret', updated_at = clock_timestamp() WHERE id = ?", [$user]);
        self::assertSame(0, $this->pendingCount());
        $this->library();
        $this->clearInbox();
        $this->writer->executeStatement('UPDATE libraries SET updated_at = clock_timestamp()');
        self::assertSame(0, $this->pendingCount());
        $this->writer->executeStatement("UPDATE users SET name = 'Changed' WHERE id = ?", [$user]);
        self::assertGreaterThan(0, $this->pendingCount());
        self::assertStringNotContainsString('secret', json_encode($this->observer->fetchAllAssociative('SELECT * FROM query_change_inbox'), JSON_THROW_ON_ERROR));
    }

    public function testTotpEnableAndDisableCaptureOnlyVisibleAccountStatus(): void
    {
        $user = $this->user();
        $this->clearInbox();
        $this->writer->executeStatement("UPDATE users SET totp_secret = 'private-secret' WHERE id = ?", [$user]);
        self::assertSame([['family' => 'account', 'scope_kind' => 'user', 'scope_id' => $user]], $this->scopes());
        $this->clearInbox();
        $this->writer->executeStatement("UPDATE users SET totp_secret = 'rotated-private-secret' WHERE id = ?", [$user]);
        self::assertSame(0, $this->pendingCount());
        $this->writer->executeStatement('UPDATE users SET totp_secret = NULL WHERE id = ?', [$user]);
        self::assertSame([['family' => 'account', 'scope_kind' => 'user', 'scope_id' => $user]], $this->scopes());
        self::assertStringNotContainsString('secret', json_encode($this->observer->fetchAllAssociative('SELECT * FROM query_change_inbox'), JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{string}> */
    public static function tokenPlanModes(): iterable
    {
        yield 'automatic plans after warmup' => ['auto'];
        yield 'generic plans' => ['force_generic_plan'];
        yield 'custom plans' => ['force_custom_plan'];
    }

    #[DataProvider('tokenPlanModes')]
    public function testOAuthCaptureUsesOnlyTheCurrentRoutesUuidReferences(string $planMode): void
    {
        $user = $this->user();
        $client = $this->id();
        $this->writer->insert('oauth_clients', [
            'id' => $client, 'public_id' => bin2hex(random_bytes(8)), 'name' => 'Capture fixture',
            'redirect' => 'https://baander.app/auth/callback',
            'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00',
        ]);
        $this->writer->executeStatement("SELECT set_config('plan_cache_mode', ?, false)", [$planMode]);
        for ($iteration = 0; $iteration < 12; ++$iteration) {
            $access = $this->id();
            $metadata = $this->id();
            $refresh = $this->id();
            $this->clearInbox();
            $this->writer->insert('oauth_access_tokens', [
                'id' => $access, 'token_id' => 'opaque-access-' . bin2hex(random_bytes(24)),
                'user_id' => $user, 'client_id' => $client,
                'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00',
            ]);
            $this->assertTokenScopes($user);
            $this->clearInbox();
            $this->writer->executeStatement("UPDATE oauth_access_tokens SET name = 'Renamed' WHERE id = ?", [$access]);
            $this->assertTokenScopes($user);
            $this->clearInbox();
            $this->writer->insert('oauth_token_metadata', [
                'id' => $metadata, 'token_id' => $access, 'device_name' => 'Original device',
                'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00',
            ]);
            $this->assertTokenScopes($user, false);
            $this->clearInbox();
            $this->writer->executeStatement("UPDATE oauth_token_metadata SET device_name = 'Renamed device' WHERE id = ?", [$metadata]);
            $this->assertTokenScopes($user, false);
            $this->clearInbox();
            $this->writer->insert('oauth_refresh_tokens', [
                'id' => $refresh, 'token_id' => 'opaque-refresh-' . bin2hex(random_bytes(24)), 'access_token_id' => $access,
                'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00',
            ]);
            $this->assertTokenScopes($user);
            $this->clearInbox();
            $this->writer->executeStatement('UPDATE oauth_refresh_tokens SET revoked = true WHERE id = ?', [$refresh]);
            $this->assertTokenScopes($user);
            foreach (['oauth_token_metadata' => $metadata, 'oauth_refresh_tokens' => $refresh] as $table => $id) {
                if ($iteration === 11) {
                    continue;
                }
                $this->clearInbox();
                $this->writer->delete($table, ['id' => $id]);
                $this->assertTokenScopes($user, $table === 'oauth_refresh_tokens');
            }
            $this->clearInbox();
            if ($iteration === 11) {
                $this->writer->delete('users', ['id' => $user]);
                foreach (['oauth_access_tokens' => $access, 'oauth_token_metadata' => $metadata, 'oauth_refresh_tokens' => $refresh] as $table => $id) {
                    self::assertSame(0, (int) $this->observer->fetchOne("SELECT count(*) FROM $table WHERE id = ?", [$id]));
                }
                self::assertContains(['family' => 'devices', 'scope_kind' => 'user', 'scope_id' => $user], $this->scopes());
            } else {
                $this->writer->delete('oauth_access_tokens', ['id' => $access]);
                $this->assertTokenScopes($user);
            }
        }
        $this->clearInbox();
        $this->writer->insert('oauth_access_tokens', [
            'id' => $access = $this->id(), 'token_id' => 'opaque-client-credentials-' . bin2hex(random_bytes(24)), 'client_id' => $client,
            'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00',
        ]);
        $adminScope = [['family' => 'admin-security', 'scope_kind' => 'admin', 'scope_id' => '*']];
        self::assertSame($adminScope, $this->scopes());
        $this->clearInbox();
        $this->writer->delete('oauth_access_tokens', ['id' => $access]);
        self::assertSame($adminScope, $this->scopes());
    }

    private function assertTokenScopes(string $user, bool $account = true): void
    {
        $expected = [
            ['family' => 'admin-security', 'scope_kind' => 'admin', 'scope_id' => '*'],
            ['family' => 'devices', 'scope_kind' => 'user', 'scope_id' => $user],
        ];
        if ($account) {
            array_unshift($expected, ['family' => 'account', 'scope_kind' => 'user', 'scope_id' => $user]);
        }
        self::assertSame($expected, $this->scopes());
    }

    public function testOwnershipMoveAndCollaboratorRemovalCaptureOldAndNewRecipients(): void
    {
        $first = $this->user();
        $second = $this->user();
        $collaborator = $this->user();
        $playlist = $this->playlist($first);
        $this->writer->insert('playlist_collaborators', ['id' => $this->id(), 'playlist_id' => $playlist, 'user_id' => $collaborator, 'role' => 'editor', 'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00']);
        $this->clearInbox();
        $this->writer->executeStatement('UPDATE playlists SET user_id = ? WHERE id = ?', [$second, $playlist]);
        $personal = array_filter($this->scopes(), static fn(array $scope): bool => $scope['family'] === 'playlists' && $scope['scope_kind'] === 'user');
        self::assertEqualsCanonicalizing([$first, $second, $collaborator], array_column($personal, 'scope_id'));
        $this->clearInbox();
        $this->writer->executeStatement('DELETE FROM playlist_collaborators WHERE playlist_id = ?', [$playlist]);
        self::assertContains(['family' => 'playlists', 'scope_kind' => 'user', 'scope_id' => $collaborator], $this->scopes());
        self::assertNotContains(['family' => 'playlists', 'scope_kind' => 'global', 'scope_id' => '*'], $this->scopes());
    }

    public function testPublicPlaylistFlagDoesNotPublishOutsideAuthorizedOwners(): void
    {
        $user = $this->user();
        $playlist = $this->playlist($user);
        $this->clearInbox();
        $this->writer->executeStatement('UPDATE playlists SET is_public = true WHERE id = ?', [$playlist]);
        self::assertContains(['family' => 'playlists', 'scope_kind' => 'user', 'scope_id' => $user], $this->scopes());
        foreach ($this->scopes() as $scope) {
            self::assertNotSame('global', $scope['scope_kind']);
        }
    }

    public function testParentDeleteRetainsPrivateDependentRecipientsBeforeCascades(): void
    {
        $user = $this->user();
        $unrelated = $this->user();
        $library = $this->library();
        $album = $this->album($library);
        $song = $this->song($album);
        $playlist = $this->playlist($user);
        $this->writer->insert('playlist_song', ['id' => $this->id(), 'playlist_id' => $playlist, 'song_id' => $song, 'position' => 0, 'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00']);
        $this->writer->insert('media_activities', ['id' => $this->id(), 'public_id' => bin2hex(random_bytes(8)), 'user_id' => $user, 'activity_type' => 'song', 'song_id' => $song, 'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00']);
        $this->clearInbox();
        $this->writer->executeStatement('DELETE FROM libraries WHERE id = ?', [$library]);
        $scopes = $this->scopes();
        self::assertContains(['family' => 'activity', 'scope_kind' => 'user', 'scope_id' => $user], $scopes);
        self::assertContains(['family' => 'playlists', 'scope_kind' => 'user', 'scope_id' => $user], $scopes);
        self::assertContains(['family' => 'catalog', 'scope_kind' => 'library', 'scope_id' => $library], $scopes);
        foreach ($scopes as $scope) {
            self::assertNotSame($unrelated, $scope['scope_id']);
            if (in_array($scope['family'], ['activity', 'playlists', 'recommendations', 'notifications'], true)) {
                self::assertNotSame('library', $scope['scope_kind'], 'Private dependency families require their own owners.');
            }
        }
    }

    public function testMovingCatalogRowCapturesBothLibraryScopes(): void
    {
        $old = $this->library();
        $new = $this->library();
        $album = $this->album($old);
        $this->clearInbox();
        $this->writer->executeStatement('UPDATE albums SET library_id = ? WHERE id = ?', [$new, $album]);
        $catalog = array_filter($this->scopes(), static fn(array $scope): bool => $scope['family'] === 'catalog');
        self::assertEqualsCanonicalizing([$old, $new], array_column($catalog, 'scope_id'));
    }

    public function testNotificationCommitIsIndependentOfEarlierSourceCommit(): void
    {
        $user = $this->user();
        $this->clearInbox();
        $outboxId = (int) $this->writer->fetchOne("INSERT INTO domain_event_outbox (event_class, event_name, payload, created_at) VALUES ('Event', 'test', '{}'::jsonb, now()) RETURNING id");
        self::assertSame(0, $this->pendingCount());
        $listeners = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $listeners->addListener(\App\Auth\Domain\Event\UserRegistered::class, function () use ($user): void {
            $this->notification($user);
            self::assertTrue($this->writer->isTransactionActive());
            self::assertSame(0, $this->pendingCount());
        });
        $manager = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($this->writer);
        $manager->method('isOpen')->willReturn(true);
        $registry = $this->createStub(\Doctrine\Persistence\ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $replayer = new \App\Shared\Infrastructure\Event\OutboxEventDispatcher($registry, $listeners);
        $event = new \App\Auth\Domain\Event\UserRegistered(
            \App\Shared\Domain\Model\Uuid::fromString($user),
            \App\Shared\Domain\Model\PublicId::fromString('bbbbbbbbbbbbbbbbbbbbb'),
            \App\Shared\Domain\Model\Email::fromString('notification@baander.app'),
            'Notification recipient',
        );
        $replayer->dispatch($event, $outboxId);
        self::assertSame([['family' => 'notifications', 'scope_kind' => 'user', 'scope_id' => $user]], $this->scopes());
        $this->clearInbox();
        $replayer->dispatch($event, $outboxId);
        self::assertSame(0, $this->pendingCount(), 'Receipt replay must not manufacture a second notification change.');
    }

    public function testFileAvailabilityChangesDirtyPlaylistAndRecommendationOwners(): void
    {
        $user = $this->user();
        $library = $this->library();
        $song = $this->song($this->album($library));
        $playlist = $this->playlist($user);
        $this->writer->insert('playlist_song', ['id' => $this->id(), 'playlist_id' => $playlist, 'song_id' => $song, 'position' => 0, 'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00']);
        $this->writer->insert('recommendations', ['id' => $this->id(), 'public_id' => bin2hex(random_bytes(8)), 'user_id' => $user, 'source_type' => 'song', 'source_id' => $song, 'target_type' => 'song', 'target_id' => $song, 'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00']);
        $this->clearInbox();
        $file = $this->id();
        $this->writer->insert('library_file_index', ['id' => $file, 'library_id' => $library, 'path' => '/fixture/' . $song, 'hash' => 'fixture', 'size' => 100, 'extension' => 'flac', 'modified_at' => 1, 'discovered_at' => '2026-10-30 12:00:00+00']);
        foreach (['playlists', 'recommendations'] as $family) {
            self::assertContains(['family' => $family, 'scope_kind' => 'user', 'scope_id' => $user], $this->scopes());
        }
        $this->clearInbox();
        $this->writer->executeStatement('DELETE FROM library_file_index WHERE id = ?', [$file]);
        foreach (['playlists', 'recommendations'] as $family) {
            self::assertContains(['family' => $family, 'scope_kind' => 'user', 'scope_id' => $user], $this->scopes());
        }
    }

    public function testSharedRecommendationHintsRequireBothLibrariesAndKeepPrivateOwners(): void
    {
        $reader = $this->user();
        $partial = $this->user();
        $private = $this->user();
        $admin = $this->user();
        $first = $this->library();
        $second = $this->library();
        $source = $this->song($this->album($first));
        $target = $this->song($this->album($second));
        $this->writer->executeStatement("UPDATE users SET roles = '[\"ROLE_ADMIN\"]' WHERE id = ?", [$admin]);
        foreach ([[$reader, $first], [$reader, $second], [$partial, $first]] as [$principal, $library]) {
            $this->writer->insert('user_library_access', ['user_id' => $principal, 'library_id' => $library]);
        }
        $this->clearInbox();
        $row = ['id' => $this->id(), 'public_id' => bin2hex(random_bytes(8)), 'source_type' => 'song', 'source_id' => $source, 'target_type' => 'song', 'target_id' => $target, 'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00'];
        $this->writer->insert('recommendations', $row);
        $recommendations = array_filter($this->scopes(), static fn(array $scope): bool => $scope['family'] === 'recommendations');
        self::assertEqualsCanonicalizing([$reader, $admin], array_column($recommendations, 'scope_id'));
        foreach ($recommendations as $scope) {
            self::assertSame('user', $scope['scope_kind']);
        }
        $this->clearInbox();
        $row['id'] = $this->id();
        $row['public_id'] = bin2hex(random_bytes(8));
        $row['user_id'] = $private;
        $this->writer->insert('recommendations', $row);
        $recommendations = array_filter($this->scopes(), static fn(array $scope): bool => $scope['family'] === 'recommendations');
        self::assertSame([$private], array_column($recommendations, 'scope_id'));
    }

    public function testReverseCoverReferenceCapturesOwnersBeforeImageDeletion(): void
    {
        $user = $this->user();
        $library = $this->library();
        $album = $this->album($library);
        $song = $this->song($album);
        $image = $this->id();
        $this->writer->insert('images', ['id' => $image, 'public_id' => bin2hex(random_bytes(8)), 'path' => '/fixture/cover.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'width' => 10, 'height' => 10, 'imageable_type' => 'album', 'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00']);
        $this->writer->executeStatement('UPDATE albums SET cover_image_id = ? WHERE id = ?', [$image, $album]);
        $this->writer->insert('media_activities', ['id' => $this->id(), 'public_id' => bin2hex(random_bytes(8)), 'user_id' => $user, 'activity_type' => 'song', 'song_id' => $song, 'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00']);
        $this->clearInbox();
        $this->writer->executeStatement('DELETE FROM images WHERE id = ?', [$image]);
        self::assertContains(['family' => 'activity', 'scope_kind' => 'user', 'scope_id' => $user], $this->scopes());
        self::assertContains(['family' => 'catalog', 'scope_kind' => 'library', 'scope_id' => $library], $this->scopes());
        self::assertNull($this->observer->fetchOne('SELECT cover_image_id FROM albums WHERE id = ?', [$album]));
    }

    public function testSettingsChangesDirtyPersonalRecommendationOwnersWithoutCopyingValues(): void
    {
        $user = $this->user();
        $library = $this->library();
        $this->writer->insert('user_library_access', ['user_id' => $user, 'library_id' => $library]);
        $this->clearInbox();
        $this->writer->insert('system_settings', ['key' => 'recommendation.fixture', 'value' => '{"secret":"never-in-inbox"}']);
        self::assertContains(['family' => 'recommendations', 'scope_kind' => 'user', 'scope_id' => $user], $this->scopes());
        self::assertStringNotContainsString('never-in-inbox', json_encode($this->observer->fetchAllAssociative('SELECT * FROM query_change_inbox'), JSON_THROW_ON_ERROR));
    }

    public function testRejectedPreferenceVersionDoesNotCaptureAndSuccessfulCasDoes(): void
    {
        $user = $this->user();
        $this->writer->insert('audio_preferences', ['id' => $this->id(), 'user_id' => $user, 'payload' => '{}', 'version' => 1, 'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00']);
        $this->clearInbox();
        self::assertSame(0, $this->writer->executeStatement("UPDATE audio_preferences SET payload = '{\"volume\":0.5}', version = version + 1 WHERE user_id = ? AND version = 9", [$user]));
        self::assertSame(0, $this->pendingCount());
        self::assertSame(1, $this->writer->executeStatement("UPDATE audio_preferences SET payload = '{\"volume\":0.5}', version = version + 1 WHERE user_id = ? AND version = 1", [$user]));
        self::assertSame([['family' => 'preferences', 'scope_kind' => 'user', 'scope_id' => $user]], $this->scopes());
    }

    public function testCaptureFailureRollsBackTheBusinessWrite(): void
    {
        $user = $this->user();
        $this->clearInbox();
        $this->writer->executeStatement("ALTER TABLE query_change_inbox ADD CONSTRAINT reject_notification_fixture CHECK (family <> 'notifications')");
        try {
            $this->notification($user);
            self::fail('Capture errors must fail the mutation.');
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            self::assertSame('23514', $error->getSQLState());
            self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM notifications'));
            self::assertSame(0, $this->pendingCount());
        }
    }

    private function id(): string
    {
        return \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
    }

    private function user(): string
    {
        $id = $this->id();
        $this->writer->insert('users', ['id' => $id, 'public_id' => bin2hex(random_bytes(8)), 'name' => 'Fixture', 'email' => bin2hex(random_bytes(8)) . '@baander.app', 'password' => 'secret', 'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00']);
        return $id;
    }

    private function notification(string $user): void
    {
        $this->writer->insert('notifications', ['id' => $this->id(), 'public_id' => bin2hex(random_bytes(8)), 'user_id' => $user, 'category' => 'system', 'event_type' => 'fixture', 'title' => 'Committed', 'body' => 'Body']);
    }

    private function playlist(string $user): string
    {
        $id = $this->id();
        $this->writer->insert('playlists', ['id' => $id, 'public_id' => bin2hex(random_bytes(8)), 'user_id' => $user, 'name' => 'Fixture', 'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00']);
        return $id;
    }

    private function library(): string
    {
        $id = $this->id();
        $this->writer->insert('libraries', ['id' => $id, 'public_id' => bin2hex(random_bytes(8)), 'name' => 'Fixture', 'slug' => bin2hex(random_bytes(8)), 'path' => '/fixture/' . $id, 'type' => 'music', 'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00']);
        return $id;
    }

    private function album(string $library): string
    {
        $id = $this->id();
        $this->writer->insert('albums', ['id' => $id, 'public_id' => bin2hex(random_bytes(8)), 'library_id' => $library, 'title' => 'Fixture', 'type' => 'album', 'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00']);
        return $id;
    }

    private function song(string $album): string
    {
        $id = $this->id();
        $this->writer->insert('songs', ['id' => $id, 'public_id' => bin2hex(random_bytes(8)), 'album_id' => $album, 'title' => 'Fixture', 'path' => '/fixture/' . $id, 'size' => 100, 'mime_type' => 'audio/flac', 'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00']);
        return $id;
    }

    private function clearInbox(): void
    {
        $this->writer->executeStatement('DELETE FROM query_change_inbox');
    }

    private function pendingCount(): int
    {
        return (int) $this->observer->fetchOne('SELECT count(*) FROM query_change_inbox');
    }

    /** @return list<array{family: string, scope_kind: string, scope_id: string}> */
    private function scopes(): array
    {
        return $this->observer->fetchAllAssociative('SELECT DISTINCT family, scope_kind, scope_id FROM query_change_inbox ORDER BY family, scope_kind, scope_id');
    }
}
