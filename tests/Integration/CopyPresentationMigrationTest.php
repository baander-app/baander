<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CopyPresentationMigrationTest extends TestCase
{
    private Connection $connection;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $this->schema = 'copy_migration_' . bin2hex(random_bytes(8));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        $this->connection->executeStatement('CREATE TABLE songs (id TEXT PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE transcode_jobs (id TEXT PRIMARY KEY, owner_user_id TEXT, video_id TEXT NOT NULL, quality_tier_name TEXT NOT NULL)');
        $this->connection->executeStatement('CREATE UNIQUE INDEX uniq_transcode_jobs_owner_user_id_video_id_quality_tier_name ON transcode_jobs (owner_user_id, video_id, quality_tier_name)');
        $this->connection->executeStatement(<<<'SQL'
CREATE TABLE audio_rendition_runtime (
    rendition_key TEXT PRIMARY KEY, track_key TEXT NOT NULL, source_signature TEXT NOT NULL,
    format TEXT NOT NULL, bitrate INT NOT NULL, state TEXT NOT NULL, generation INT NOT NULL,
    CONSTRAINT uniq_audio_rendition_runtime_canonical UNIQUE (track_key, source_signature, format, bitrate),
    CONSTRAINT chk_audio_rendition_state CHECK (state IN ('pending', 'running', 'complete', 'failed') AND generation >= 0 AND bitrate > 0)
)
SQL);
        $this->connection->executeStatement("INSERT INTO songs VALUES ('song')");
        $this->connection->executeStatement("INSERT INTO transcode_jobs VALUES ('job', 'owner', 'video', '720p')");
        $this->connection->executeStatement("INSERT INTO audio_rendition_runtime VALUES ('audio', 'track', 'signature', 'aac', 128000, 'complete', 1)");
        require_once dirname(__DIR__, 2) . '/migrations/Version20261102190000.php';
        $migration = new \DoctrineMigrations\Version20261102190000($this->connection, new NullLogger());
        $migration->up(new Schema());
        $this->connection->transactional(static function (Connection $connection) use ($migration): void {
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
    }

    public function testExistingRowsRemainAndNewCopiesHaveDistinctIdentities(): void
    {
        self::assertSame(['container_format' => null, 'stream_index' => null], $this->connection->fetchAssociative("SELECT container_format, stream_index FROM songs WHERE id = 'song'"));
        self::assertSame(['presentation_selection' => null, 'presentation_key' => 'legacy', 'request_id' => 'job'], $this->connection->fetchAssociative("SELECT presentation_selection, presentation_key, request_id FROM transcode_jobs WHERE id = 'job'"));
        self::assertSame(['mode' => 'encode', 'bitrate' => 128000, 'state' => 'complete'], $this->connection->fetchAssociative("SELECT mode, bitrate, state FROM audio_rendition_runtime WHERE rendition_key = 'audio'"));
        self::assertTrue($this->connection->fetchOne("SELECT attnotnull AND NOT atthasdef FROM pg_attribute WHERE attrelid = 'transcode_jobs'::regclass AND attname = 'request_id'"));
        $this->connection->executeStatement("INSERT INTO transcode_jobs (id, owner_user_id, video_id, quality_tier_name, presentation_key, request_id) VALUES ('new-job', 'owner', 'video', '720p', 'new-source-proof', 'first-client')");
        $this->connection->executeStatement("INSERT INTO audio_rendition_runtime (rendition_key, track_key, source_signature, format, bitrate, state, generation, mode, stream_index, output_container, source_hash) VALUES ('copy', 'track', 'signature', 'aac', NULL, 'pending', 0, 'copy', 2, 'mp4', '1234567890abcdef')");
        $this->connection->executeStatement("INSERT INTO transcode_jobs (id, owner_user_id, video_id, quality_tier_name, presentation_key, request_id) VALUES ('second-client-job', 'owner', 'video', '720p', 'new-source-proof', 'second-client')");
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT count(*) FROM transcode_jobs'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM audio_rendition_runtime'));
    }

    public function testCopyDoesNotAcceptAnEncodingBitrate(): void
    {
        $this->expectException(DriverException::class);
        $this->connection->executeStatement("INSERT INTO audio_rendition_runtime (rendition_key, track_key, source_signature, format, bitrate, state, generation, mode, stream_index, output_container, source_hash) VALUES ('copy', 'track', 'signature', 'aac', 128000, 'pending', 0, 'copy', 2, 'mp4', '1234567890abcdef')");
    }
}
