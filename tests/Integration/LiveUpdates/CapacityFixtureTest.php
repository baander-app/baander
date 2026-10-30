<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveUpdates;

use App\Shared\Infrastructure\LiveUpdates\DoctrineLiveChangeProjector;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2) . '/Fixtures/LiveUpdates/capacity-fixture.php';

final class CapacityFixtureTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private string $schema;
    private string $root;
    private \LiveCapacityFixture $fixture;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->writer = DriverManager::getConnection($params);
        $this->observer = DriverManager::getConnection($params);
        $this->schema = 'live_capacity_' . bin2hex(random_bytes(8));
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->writer, $this->observer] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
            $connection->executeStatement("SET TIME ZONE 'UTC'");
        }
        $files = glob(dirname(__DIR__, 3) . '/migrations/Version*.php');
        sort($files, SORT_STRING);
        foreach ($files as $file) {
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
        $this->root = sys_get_temp_dir() . '/' . $this->schema;
        $this->fixture = new \LiveCapacityFixture($this->writer, $this->root);
    }

    protected function tearDown(): void
    {
        if (!isset($this->writer)) {
            return;
        }
        $this->observer->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        if (isset($this->fixture)) {
            $this->fixture->close();
        }
        $this->observer->close();
        if (isset($this->root) && is_dir($this->root)) {
            foreach (glob($this->root . '/*') as $directory) {
                foreach (glob($directory . '/*') as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
            rmdir($this->root);
        }
    }

    public function testProducerCapturesEveryNotificationAndCleanupPreservesOtherRuns(): void
    {
        $owned = $this->fixture->execute(['op' => 'create', 'run' => 'owned']);
        $other = $this->fixture->execute(['op' => 'create', 'run' => 'other']);
        self::assertCount(32, $owned['users']);
        self::assertCount(32, array_unique(array_column($owned['users'], 'id')));
        self::assertSame([false], array_values(array_unique(array_column($owned['users'], 'admin'))));
        self::assertTrue($owned['observer']['admin']);
        $hash = $this->observer->fetchOne('SELECT password FROM users WHERE id = ?', [$owned['users'][0]['id']]);
        self::assertTrue(password_verify($owned['users'][0]['password'], $hash));
        self::assertStringEndsWith('@baander.app', $owned['users'][0]['email']);
        $wave = file_get_contents($owned['song']['path']);
        self::assertSame('RIFF', substr($wave, 0, 4));
        self::assertSame(60 * 8000 * 2 + 44, strlen($wave));
        self::assertSame(32, (int) $this->observer->fetchOne('SELECT count(*) FROM user_library_access WHERE library_id = ? AND user_id <> ?', [$owned['library']['id'], $owned['observer']['id']]));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM playlist_collaborators WHERE playlist_id = ? AND user_id = ?', [$owned['playlist']['id'], $owned['users'][3]['id']]));
        $projector = new DoctrineLiveChangeProjector($this->observer);
        $this->drain($projector);
        $ordinary = $this->fixture->execute(['op' => 'ordinary', 'run' => 'owned']);
        self::assertSame(32, $ordinary['capturedRows']);
        self::assertSame(array_fill(0, 32, 1), array_column($ordinary['users'], 'count'));
        self::assertGreaterThanOrEqual($ordinary['commitStartEpochMs'], $ordinary['commitEndEpochMs']);
        self::assertSame(32, (int) $this->observer->fetchOne("SELECT count(*) FROM query_change_inbox WHERE family = 'notifications'"));
        $burst = $this->fixture->execute(['op' => 'burst', 'run' => 'owned', 'count' => 100, 'durationMs' => 0, 'sequence' => 2]);
        self::assertSame(100, $burst['capturedRows']);
        self::assertSame(132, array_sum(array_column($burst['users'], 'count')));
        $catalog = $this->fixture->execute(['op' => 'catalog', 'run' => 'owned', 'albums' => 12, 'songs' => 10]);
        self::assertSame(12, $catalog['albums']);
        $sample = $this->fixture->execute(['op' => 'sample', 'run' => 'owned']);
        self::assertSame(['albums' => 12, 'songs' => 10], $sample['catalog']);
        $changed = $this->fixture->execute(['op' => 'change', 'run' => 'owned', 'target' => 'album', 'value' => 'Remote title']);
        self::assertSame('Remote title', $changed['entity']['title']);
        $this->fixture->execute(['op' => 'authority', 'run' => 'owned', 'target' => 'library', 'userIndex' => 1]);
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM user_library_access WHERE library_id = ? AND user_id = ?', [$owned['library']['id'], $owned['users'][1]['id']]));
        $this->fixture->execute(['op' => 'authority', 'run' => 'owned', 'target' => 'library', 'userIndex' => 1, 'grant' => true]);
        $held = $this->fixture->execute(['op' => 'projector', 'run' => 'owned', 'hold' => true]);
        self::assertTrue($held['held']);
        self::assertSame(0, $projector->project());
        self::assertSame('Remote title', $this->observer->fetchOne('SELECT title FROM albums WHERE id = ?', [$owned['album']['id']]));
        $this->fixture->execute(['op' => 'ordinary', 'run' => 'owned', 'sequence' => 3]);
        $this->fixture->execute(['op' => 'projector', 'run' => 'owned', 'hold' => false]);
        $partial = $this->fixture->execute(['op' => 'cleanup', 'run' => 'owned', 'waitMs' => 0]);
        self::assertFalse($partial['complete']);
        self::assertTrue($partial['manifestRetained']);
        self::assertFileExists($this->root . '/owned/manifest.json');
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM users WHERE id = ?', [$owned['users'][0]['id']]));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM users WHERE id = ?', [$other['users'][0]['id']]));
        self::assertFileExists($other['song']['path']);
        $this->drain($projector);
        // An interrupted producer may have left ownership recorded before writing media.
        unlink($owned['song']['path']);
        $complete = $this->fixture->execute(['op' => 'cleanup', 'run' => 'owned', 'waitMs' => 0]);
        self::assertTrue($complete['complete']);
        self::assertSame(0, $complete['survivors']);
        self::assertDirectoryDoesNotExist($this->root . '/owned');
        self::assertNotEmpty($this->observer->fetchAllAssociative('SELECT * FROM query_scope_revision'), 'Cleanup retains revision tombstones.');
        $this->fixture->execute(['op' => 'cleanup', 'run' => 'other', 'waitMs' => 0]);
        $this->drain($projector);
        self::assertTrue($this->fixture->execute(['op' => 'cleanup', 'run' => 'other', 'waitMs' => 0])['complete']);
    }

    private function drain(DoctrineLiveChangeProjector $projector): void
    {
        for ($i = 0; $i < 100 && $projector->project() !== 0; ++$i) {
        }
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM query_change_inbox'));
    }
}
