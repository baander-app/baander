<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use DoctrineMigrations\Version20261126220235;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2) . '/migrations/Version20261126220235.php';

/** Songs a scan stored as audio/opus carry audio/ogg, the type RFC 7845 registers for Ogg Opus files. */
final class OggOpusMediaTypeMigrationTest extends TestCase
{
    private Connection $connection;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->schema = 'ogg_opus_media_type_' . (random_bytes(8) |> bin2hex(...));
        $this->connection = DriverManager::getConnection(new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        FinalSchemaBootstrap::bootstrap($this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
    }

    public function testOnlyTheOpusSongsChangeTypeAndADowngradeRestoresThem(): void
    {
        $this->migrate('down');
        $album = $this->insertAlbum();
        $this->insertSong($album, '/music/Opening.opus', 'audio/opus');
        $this->insertSong($album, '/music/Vorbis.ogg', 'audio/ogg');
        $this->insertSong($album, '/music/Lossless.flac', 'audio/flac');

        $this->migrate('up');

        self::assertSame([
            '/music/Lossless.flac' => 'audio/flac',
            '/music/Opening.opus' => 'audio/ogg',
            '/music/Vorbis.ogg' => 'audio/ogg',
        ], $this->types());

        $this->migrate('down');

        self::assertSame([
            '/music/Lossless.flac' => 'audio/flac',
            '/music/Opening.opus' => 'audio/opus',
            '/music/Vorbis.ogg' => 'audio/ogg',
        ], $this->types());
    }

    private function migrate(string $direction): void
    {
        $migration = new Version20261126220235($this->connection, new NullLogger());
        $migration->{$direction}(new Schema());
        $this->connection->transactional(static function (Connection $connection) use ($migration): void {
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        });
    }

    private function insertAlbum(): Uuid
    {
        $library = Uuid::generate();
        $this->connection->executeStatement(
            "INSERT INTO libraries (id, public_id, name, slug, path, type, created_at, updated_at) VALUES (?, ?, 'Music', 'music', '/music', 'music', clock_timestamp(), clock_timestamp())",
            [$library->toString(), new PublicId()->toString()],
        );
        $album = Uuid::generate();
        $this->connection->executeStatement(
            "INSERT INTO albums (id, public_id, library_id, title, type, created_at, updated_at) VALUES (?, ?, ?, 'Formats', 'album', clock_timestamp(), clock_timestamp())",
            [$album->toString(), new PublicId()->toString(), $library->toString()],
        );

        return $album;
    }

    private function insertSong(Uuid $album, string $path, string $type): void
    {
        $this->connection->executeStatement(
            "INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, created_at, updated_at) VALUES (?, ?, ?, 'Track', ?, 1, ?, clock_timestamp(), clock_timestamp())",
            [Uuid::generate()->toString(), new PublicId()->toString(), $album->toString(), $path, $type],
        );
    }

    /** @return array<string, string> each song's path and stored type, by path */
    private function types(): array
    {
        return $this->connection->fetchAllKeyValue('SELECT path, mime_type FROM songs ORDER BY path COLLATE "C"');
    }
}
