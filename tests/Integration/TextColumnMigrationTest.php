<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use PHPUnit\Framework\TestCase;

/** Application string columns are TEXT; length rules live in application code. */
final class TextColumnMigrationTest extends TestCase
{
    use OwnershipPersistenceHarness;

    private const CONVERTED = [
        'movies' => ['backdrop_url', 'collection_name', 'imdb_id', 'original_language', 'poster_url', 'tagline'],
        'movie_collections' => ['backdrop_path', 'name', 'poster_path'],
        'job_monitors' => ['status'],
        'oauth_auth_codes' => ['code_challenge_method'],
        'domain_event_outbox_delivery' => ['notification_id'],
        'domain_event_outbox_receipt' => ['consumer'],
    ];

    public function testApplicationStringColumnsAreText(): void
    {
        self::assertSame(array_fill_keys($this->convertedColumns(), 'text'), $this->columnTypes());
        // Doctrine Migrations owns its metadata table; every application column is TEXT.
        self::assertSame(['doctrine_migration_versions.version'], $this->manager->getConnection()->fetchFirstColumn(
            "SELECT table_name || '.' || column_name FROM information_schema.columns
              WHERE table_schema = current_schema() AND data_type IN ('character varying', 'character')
              ORDER BY 1",
        ));
        self::assertSame([], $this->varcharDefaults());
    }

    public function testSchemaComparisonIsCleanForTheMappedTables(): void
    {
        // The domain_event_outbox tables are migration-owned and excluded by the DBAL schema filter.
        $this->assertSchemaComparisonIsClean(['movies', 'movie_collections', 'job_monitors', 'oauth_auth_codes']);
    }

    public function testTextColumnsKeepStoredValuesWithoutFormerLengthLimits(): void
    {
        $connection = $this->manager->getConnection();
        $rows = $this->insertRows();

        self::assertSame(array_fill_keys($this->convertedColumns(), 'text'), $this->columnTypes());
        self::assertSame([], $this->varcharDefaults());
        foreach ($rows as $table => [$key, $keyValue, $values]) {
            self::assertSame($values, $connection->fetchAssociative(
                sprintf('SELECT %s FROM %s WHERE %s = :key', implode(', ', array_keys($values)), $table, $key),
                ['key' => $keyValue],
            ), $table);
        }

        // Former VARCHAR(255) rejected this; TMDB taglines are not bounded by the application.
        $tagline = str_repeat('t', 300);
        $connection->executeStatement('UPDATE movies SET tagline = :tagline WHERE id = :id', ['tagline' => $tagline, 'id' => $rows['movies'][1]]);
        self::assertSame($tagline, $connection->fetchOne('SELECT tagline FROM movies WHERE id = :id', ['id' => $rows['movies'][1]]));
    }

    /** @return array<string, array{string, string|int, array<string, string|int>}> table => [key column, key, stored values] */
    private function insertRows(): array
    {
        $connection = $this->manager->getConnection();
        $now = '2026-10-06 21:00:00+00';
        $suffix = bin2hex(random_bytes(6));

        $library = Uuid::generate()->toString();
        $connection->insert('libraries', ['id' => $library, 'public_id' => (new PublicId())->toString(), 'name' => 'U19 ' . $suffix, 'slug' => 'u19-' . $suffix,
            'path' => '/media/u19/' . $suffix, 'type' => 'movie', 'created_at' => $now, 'updated_at' => $now]);
        $movie = Uuid::generate()->toString();
        $movieValues = [
            'backdrop_url' => 'https://image.tmdb.org/t/p/w1280/' . str_repeat('b', 200) . '.jpg',
            'collection_name' => 'U19 Collection',
            'imdb_id' => 'tt0137523',
            'original_language' => 'da',
            'poster_url' => 'https://image.tmdb.org/t/p/w500/u19.jpg',
            'tagline' => str_repeat('æ', 255),
        ];
        $connection->insert('movies', ['id' => $movie, 'public_id' => (new PublicId())->toString(), 'library_id' => $library,
            'title' => 'U19 movie', 'created_at' => $now, 'updated_at' => $now] + $movieValues);

        $collectionValues = ['backdrop_path' => '/u19-backdrop.jpg', 'name' => 'U19 Collection', 'poster_path' => '/u19-poster.jpg'];
        $collection = Uuid::generate()->toString();
        $connection->insert('movie_collections', ['id' => $collection, 'tmdb_collection_id' => random_int(1, 2_000_000_000)] + $collectionValues);

        $job = Uuid::generate()->toString();
        $connection->insert('job_monitors', ['id' => $job, 'job_id' => 'u19-' . $suffix, 'status' => 'running', 'created_at' => $now, 'updated_at' => $now]);

        $client = Uuid::generate()->toString();
        $connection->insert('oauth_clients', ['id' => $client, 'public_id' => (new PublicId())->toString(), 'name' => 'U19 client',
            'redirect' => 'https://app.baander.app/callback', 'created_at' => $now, 'updated_at' => $now]);
        $authCode = Uuid::generate()->toString();
        $connection->insert('oauth_auth_codes', ['id' => $authCode, 'code_id' => 'u19-' . $suffix, 'user_id' => $this->createUser()->toString(),
            'client_id' => $client, 'redirect_uri' => 'https://app.baander.app/callback',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', 'code_challenge_method' => 'S256',
            'created_at' => $now, 'updated_at' => $now]);

        $notification = str_repeat('n', 64);
        $connection->insert('domain_event_outbox_delivery', ['channel' => 'webhook', 'notification_id' => $notification, 'payload' => '{}']);
        $outbox = (int) $connection->fetchOne(
            "INSERT INTO domain_event_outbox (event_class, event_name, payload, created_at) VALUES ('U19', 'u19.event', '{}', NOW()) RETURNING id",
        );
        $consumer = 'notifications.v1';
        $connection->insert('domain_event_outbox_receipt', ['outbox_id' => $outbox, 'consumer' => $consumer]);

        return [
            'movies' => ['id', $movie, $movieValues],
            'movie_collections' => ['id', $collection, $collectionValues],
            'job_monitors' => ['id', $job, ['status' => 'running']],
            'oauth_auth_codes' => ['id', $authCode, ['code_challenge_method' => 'S256']],
            'domain_event_outbox_delivery' => ['notification_id', $notification, ['notification_id' => $notification]],
            'domain_event_outbox_receipt' => ['outbox_id', $outbox, ['consumer' => $consumer]],
        ];
    }

    /** @return list<string> columns whose default still casts to VARCHAR */
    private function varcharDefaults(): array
    {
        return $this->manager->getConnection()->fetchFirstColumn(
            "SELECT table_name || '.' || column_name FROM information_schema.columns
              WHERE table_schema = current_schema() AND column_default LIKE '%character varying%'
              ORDER BY 1",
        );
    }

    /** @return list<string> */
    private function convertedColumns(): array
    {
        $columns = [];
        foreach (self::CONVERTED as $table => $names) {
            foreach ($names as $name) {
                $columns[] = $table . '.' . $name;
            }
        }
        sort($columns);

        return $columns;
    }

    /** @return array<string, string> */
    private function columnTypes(): array
    {
        $types = [];
        foreach ($this->manager->getConnection()->fetchAllAssociative(
            "SELECT table_name || '.' || column_name AS name, data_type FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name || '.' || column_name IN (:columns)
              ORDER BY 1",
            ['columns' => $this->convertedColumns()],
            ['columns' => ArrayParameterType::STRING],
        ) as $row) {
            $types[$row['name']] = $row['data_type'];
        }

        return $types;
    }
}
