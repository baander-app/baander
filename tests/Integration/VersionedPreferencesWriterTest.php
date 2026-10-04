<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Infrastructure\Doctrine\VersionedPreferencesWriter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionedPreferencesWriterTest extends TestCase
{
    private Connection $connection;
    private Connection $observer;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable migrated PostgreSQL.');
        }
        $parameters = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->connection = DriverManager::getConnection($parameters);
        $this->observer = DriverManager::getConnection($parameters);
        $this->schema = 'preference_version_test_' . bin2hex(random_bytes(8));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach (['audio_preferences', 'player_preferences', 'layout_preferences', 'preference_history'] as $table) {
            // Copy the migrated physical columns and indexes, without foreign user fixtures.
            $this->connection->executeStatement("CREATE TABLE {$this->schema}.{$table} (LIKE public.{$table} INCLUDING ALL)");
        }
        $this->connection->executeStatement('SET search_path TO ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema);
    }

    /** @return iterable<string, array{string, int}> */
    public static function contenders(): iterable
    {
        foreach (['audio', 'player', 'layout'] as $type) {
            yield $type . ' first save' => [$type, 0];
            yield $type . ' update' => [$type, 1];
        }
    }

    #[DataProvider('contenders')]
    public function testConcurrentWriterWaitsThenRejectsSameExpectedVersion(string $type, int $expectedVersion): void
    {
        $userId = Uuid::generate();
        $writer = new VersionedPreferencesWriter($this->connection);
        if ($expectedVersion === 1) {
            self::assertSame(1, $writer->saveForUser($type, $userId, ['winner' => 'original'], 0));
        }
        $this->connection->beginTransaction();
        self::assertSame($expectedVersion + 1, $writer->saveForUser($type, $userId, ['winner' => 'first'], $expectedVersion));
        $marker = sys_get_temp_dir() . '/baander-preference-contender-' . bin2hex(random_bytes(8));
        $output = tmpfile();
        self::assertIsResource($output);
        $code = <<<'PHP'
require $argv[1];
$params = (new \Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql']))->parse(getenv('OUTBOX_TEST_DATABASE_URL'));
$connection = \Doctrine\DBAL\DriverManager::getConnection($params);
$connection->executeStatement('SET search_path TO ' . $argv[2]);
$connection->executeStatement("SET statement_timeout TO '5s'");
file_put_contents($argv[3], (string) $connection->fetchOne('SELECT pg_backend_pid()'));
try {
    $version = (new \App\UserPreference\Infrastructure\Doctrine\VersionedPreferencesWriter($connection))->saveForUser(
        $argv[4], \App\Shared\Domain\Model\Uuid::fromString($argv[5]), ['winner' => 'second'], (int) $argv[6],
    );
    echo json_encode(['saved' => $version], JSON_THROW_ON_ERROR);
} catch (\App\UserPreference\Application\Exception\PreferenceVersionConflict $error) {
    echo json_encode(['conflict' => $error->currentVersion, 'nesting' => $connection->getTransactionNestingLevel()], JSON_THROW_ON_ERROR);
}
PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 2) . '/vendor/autoload.php', $this->schema, $marker, $type, $userId->toString(), (string) $expectedVersion],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        self::assertIsResource($process);
        try {
            $deadline = microtime(true) + 3;
            do {
                $pid = is_file($marker) ? (int) file_get_contents($marker) : 0;
                $waiting = $pid > 0 && $this->connection->fetchOne("SELECT wait_event_type = 'Lock' FROM pg_stat_activity WHERE pid = :pid", ['pid' => $pid]);
                if ($waiting) {
                    break;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            self::assertTrue((bool) $waiting, 'Independent writer must actually contend on the uncommitted preference write.');
            $this->connection->commit();
            self::assertSame(0, proc_close($process));
            rewind($output);
            self::assertSame(['conflict' => $expectedVersion + 1, 'nesting' => 0], json_decode(stream_get_contents($output), true, 512, JSON_THROW_ON_ERROR));
            $saved = $this->connection->fetchAssociative("SELECT version, payload FROM {$type}_preferences WHERE user_id = :user_id", ['user_id' => $userId->toString()]);
            self::assertNotFalse($saved);
            self::assertSame($expectedVersion + 1, (int) $saved['version']);
            self::assertSame(['winner' => 'first'], json_decode($saved['payload'], true, 512, JSON_THROW_ON_ERROR));
            self::assertSame($expectedVersion + 1, (int) $this->connection->fetchOne('SELECT count(*) FROM preference_history'));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
            fclose($output);
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    public function testHistoryFailureRollsBackPreferenceAndConnectionCanRetry(): void
    {
        $userId = Uuid::generate();
        $writer = new VersionedPreferencesWriter($this->connection);
        $writer->saveForUser('audio', $userId, ['volume' => 0.5], 0);
        $this->connection->executeStatement('ALTER TABLE preference_history ADD CONSTRAINT reject_new_snapshot CHECK (version < 2)');
        try {
            $writer->saveForUser('audio', $userId, ['volume' => 0.8], 1);
            self::fail('The history insert must fail.');
        } catch (DriverException $error) {
            self::assertSame('23514', $error->getSQLState());
        }
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT version FROM audio_preferences'));
        self::assertSame(['volume' => 0.5], json_decode($this->observer->fetchOne('SELECT payload FROM audio_preferences'), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM preference_history'));
        $this->connection->executeStatement('ALTER TABLE preference_history DROP CONSTRAINT reject_new_snapshot');
        self::assertSame(2, $writer->saveForUser('audio', $userId, ['volume' => 0.8], 1));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            $this->connection->close();
            $this->observer->close();
        }
        parent::tearDown();
    }
}
