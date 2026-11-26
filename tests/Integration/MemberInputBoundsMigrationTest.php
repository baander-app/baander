<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Playlist\Interface\Request\CreatePlaylistRequest;
use App\Tests\Support\FinalSchemaBootstrap;
use App\UserPreference\Domain\Model\SidebarItem;
use App\UserPreference\Interface\Request\UpdateEqDeviceProfileRequest;
use App\UserPreference\Interface\Request\UpdateSidebarSectionsRequest;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261125141000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Validator\Validation;

require_once dirname(__DIR__, 2) . '/migrations/Version20261125141000.php';

/**
 * The member input checks on disposable PostgreSQL: they apply over rows that already exceed
 * them, leave those rows readable, refuse new values over a bound, measure jsonb by its text
 * form, and admit the largest values the request validation accepts.
 */
final class MemberInputBoundsMigrationTest extends TestCase
{
    private const string USER = '0199bf3c-8a00-7000-8000-c4a1d0000001';

    private const array CONSTRAINTS = [
        'chk_eq_device_profiles_device_id_length',
        'chk_eq_device_profiles_payload_size',
        'chk_listening_sessions_queue_size',
        'chk_media_activities_last_platform_length',
        'chk_media_activities_last_player_length',
        'chk_playlists_description_length',
        'chk_playlists_name_length',
        'chk_playlists_smart_rules_size',
        'chk_radio_sessions_active_stream_url_length',
        'chk_user_sidebar_configs_items_size',
    ];

    private Connection $connection;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->schema = 'member_bounds_' . (random_bytes(8) |> bin2hex(...));
        $this->connection = DriverManager::getConnection(new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        FinalSchemaBootstrap::bootstrap($this->connection);
        $this->connection->executeStatement(
            "INSERT INTO users (id, public_id, name, email, password, created_at, updated_at) VALUES (?, 'bounds0000000000owner', 'Owner', 'owner@baander.app', 'hash', now(), now())",
            [self::USER],
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
    }

    public function testTheChecksApplyOverStoredRowsThatExceedThemAndLeaveThoseRowsReadable(): void
    {
        $this->migrate(new Version20261125141000($this->connection, new NullLogger()), 'down');
        $description = str_repeat('d', 3_000_000);
        $this->insertPlaylist('Stored before the bound', $description);
        $this->connection->executeStatement(
            "INSERT INTO media_activities (id, public_id, user_id, activity_type, last_platform, created_at, updated_at) VALUES (gen_random_uuid(), 'bounds000000activity1', ?, 'song', ?, now(), now())",
            [self::USER, str_repeat('p', 100)],
        );

        $this->migrate(new Version20261125141000($this->connection, new NullLogger()), 'up');

        self::assertSame(
            array_fill_keys(self::CONSTRAINTS, false),
            $this->constraintsValidated(),
            'Every check must exist NOT VALID, so stored rows are not scanned.',
        );
        self::assertSame($description, $this->connection->fetchOne("SELECT description FROM playlists WHERE name = 'Stored before the bound'"));
        self::assertSame(str_repeat('p', 100), $this->connection->fetchOne('SELECT last_platform FROM media_activities'));
        self::assertSame(1, $this->connection->executeStatement("DELETE FROM playlists WHERE name = 'Stored before the bound'"));
    }

    /** @return iterable<string, array{string, list<mixed>}> */
    public static function valuesOverTheirBounds(): iterable
    {
        yield 'playlist name' => ["INSERT INTO playlists (id, public_id, user_id, name, created_at, updated_at) VALUES (gen_random_uuid(), 'bounds0000000playlist', ?, ?, now(), now())", [str_repeat('n', 256)]];
        yield 'playlist description' => ["INSERT INTO playlists (id, public_id, user_id, name, description, created_at, updated_at) VALUES (gen_random_uuid(), 'bounds0000000playlist', ?, 'Name', ?, now(), now())", [str_repeat('d', 2001)]];
        yield 'smart rules' => ["INSERT INTO playlists (id, public_id, user_id, name, smart_rules, created_at, updated_at) VALUES (gen_random_uuid(), 'bounds0000000playlist', ?, 'Name', ?::jsonb, now(), now())", [self::json(['value' => self::incompressible(81920)])]];
        // jsonb stores a control character in one byte and prints it as a six-character escape.
        yield 'smart rules printed over the bound' => ["INSERT INTO playlists (id, public_id, user_id, name, smart_rules, created_at, updated_at) VALUES (gen_random_uuid(), 'bounds0000000playlist', ?, 'Name', ?::jsonb, now(), now())", [self::json([['field' => 'genre', 'operator' => 'contains', 'value' => str_repeat("\u{1}", 14_000)]])]];
        yield 'activity platform' => ["INSERT INTO media_activities (id, public_id, user_id, activity_type, last_platform, created_at, updated_at) VALUES (gen_random_uuid(), 'bounds000000activity2', ?, 'song', ?, now(), now())", [str_repeat('p', 65)]];
        yield 'activity player' => ["INSERT INTO media_activities (id, public_id, user_id, activity_type, last_player, created_at, updated_at) VALUES (gen_random_uuid(), 'bounds000000activity3', ?, 'song', ?, now(), now())", [str_repeat('p', 65)]];
        yield 'eq device id' => ["INSERT INTO eq_device_profiles (id, public_id, user_id, name, device_id) VALUES (gen_random_uuid(), 'bounds00000000000eqp1', ?, 'Name', ?)", [str_repeat('i', 256)]];
        yield 'eq payload' => ["INSERT INTO eq_device_profiles (id, public_id, user_id, name, payload) VALUES (gen_random_uuid(), 'bounds00000000000eqp2', ?, 'Name', ?::jsonb)", [self::json(['bands' => self::incompressible(655360)])]];
        // jsonb stores 5.0e-324 as a numeric of a few bytes and prints all of its 325 decimals.
        yield 'eq payload printed over the bound' => ["INSERT INTO eq_device_profiles (id, public_id, user_id, name, payload) VALUES (gen_random_uuid(), 'bounds00000000000eqp3', ?, 'Name', ?::jsonb)", [self::json(array_fill(0, 2000, 5.0E-324))]];
        yield 'sidebar items' => ["INSERT INTO user_sidebar_configs (id, user_id, media_type, items, created_at, updated_at) VALUES (gen_random_uuid(), ?, 'music', ?::jsonb, now(), now())", [self::json([self::incompressible(655360)])]];
        yield 'radio stream url' => ["INSERT INTO radio_sessions (id, user_id, active_stream_url, created_at, updated_at) VALUES (gen_random_uuid(), ?, ?, now(), now())", ['https://radio.baander.app/' . str_repeat('s', 2048)]];
        yield 'session queue' => ["INSERT INTO listening_sessions (id, public_id, user_id, queue, created_at, updated_at) VALUES (gen_random_uuid(), 'bounds0000000session1', ?, ?::jsonb, now(), now())", [array_fill(0, 12_500, str_repeat('q', 21)) |> self::json(...)]];
    }

    /** @param list<mixed> $parameters */
    #[DataProvider('valuesOverTheirBounds')]
    public function testAValueOverItsBoundIsRefused(string $sql, array $parameters): void
    {
        try {
            $this->connection->executeStatement($sql, [self::USER, ...$parameters]);
            self::fail('The check must refuse the value.');
        } catch (DriverException $refusal) {
            self::assertSame('23514', $refusal->getSQLState());
        }
    }

    public function testTheLargestValuesValidationAcceptsAreStored(): void
    {
        $rules = array_fill(0, 50, ['field' => 'date_added', 'operator' => 'is_not_empty', 'value' => str_repeat('😀', 255)]);
        $this->connection->executeStatement(
            "INSERT INTO playlists (id, public_id, user_id, name, description, smart_rules, created_at, updated_at) VALUES (gen_random_uuid(), 'bounds00000000largest', ?, ?, ?, ?::jsonb, now(), now())",
            [self::USER, str_repeat('ø', 255), str_repeat('ø', 2000), self::json($rules)],
        );
        // The densest jsonb that 16384 bytes of compact JSON can encode: one-digit numbers.
        $densest = '[' . str_repeat('0,', 8191) . '0]';
        self::assertSame(16385, strlen($densest));
        $this->connection->executeStatement(
            "INSERT INTO eq_device_profiles (id, public_id, user_id, name, device_id, payload) VALUES (gen_random_uuid(), 'bounds00000000largest', ?, 'Largest', ?, ?::jsonb)",
            [self::USER, str_repeat('ø', 255), $densest],
        );
        $this->connection->executeStatement(
            "INSERT INTO user_sidebar_configs (id, user_id, media_type, items, created_at, updated_at) VALUES (gen_random_uuid(), ?, 'music', ?::jsonb, now(), now())",
            [self::USER, $densest],
        );
        $this->connection->executeStatement(
            "INSERT INTO listening_sessions (id, public_id, user_id, queue, created_at, updated_at) VALUES (gen_random_uuid(), 'bounds00000000largest', ?, ?::jsonb, now(), now())",
            [self::USER, self::json(array_fill(0, 10_000, 'aB3dE5fG7hJ9kL1mN3pQr'))],
        );

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM listening_sessions'));
        self::assertSame(10_000, (int) $this->connection->fetchOne('SELECT jsonb_array_length(queue) FROM listening_sessions'));
    }

    public function testTheLongestTextFormsValidationAcceptsAreStored(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        // Control characters print as six-character escapes: 79800 bytes of text.
        $rules = array_fill(0, 50, ['field' => 'date_added', 'operator' => 'is_not_empty', 'value' => str_repeat("\u{1}", 255)]);
        self::assertCount(0, $validator->validate(new CreatePlaylistRequest(name: 'Longest', smartRules: $rules)));
        // 5.0e-324 prints with all of its 325 decimals: 16381 bytes of compact JSON print as 598780.
        $payload = array_fill(0, 1820, 5.0E-324);
        self::assertCount(0, $validator->validate(new UpdateEqDeviceProfileRequest(payload: $payload)));
        $sections = [['items' => [['id' => '', 'type' => 'page_link', 'label' => '', 'icon' => '', 'config' => array_fill(0, 1812, 5.0E-324)]]]];
        self::assertCount(0, $validator->validate(new UpdateSidebarSectionsRequest(sections: $sections)));
        // The sidebar stores the submitted items as its domain model writes them.
        $items = array_map(static fn (array $item): array => SidebarItem::fromArray($item)->toArray(), $sections[0]['items']);

        $this->connection->executeStatement(
            "INSERT INTO playlists (id, public_id, user_id, name, smart_rules, created_at, updated_at) VALUES (gen_random_uuid(), 'bounds000000longtext1', ?, 'Longest', ?::jsonb, now(), now())",
            [self::USER, self::json($rules)],
        );
        $this->connection->executeStatement(
            "INSERT INTO eq_device_profiles (id, public_id, user_id, name, payload) VALUES (gen_random_uuid(), 'bounds000000longtext1', ?, 'Longest', ?::jsonb)",
            [self::USER, self::json($payload)],
        );
        $this->connection->executeStatement(
            "INSERT INTO user_sidebar_configs (id, user_id, media_type, items, created_at, updated_at) VALUES (gen_random_uuid(), ?, 'music', ?::jsonb, now(), now())",
            [self::USER, self::json($items)],
        );

        self::assertSame(
            [79_800, 598_780],
            [
                (int) $this->connection->fetchOne('SELECT octet_length(smart_rules::text) FROM playlists'),
                (int) $this->connection->fetchOne('SELECT octet_length(payload::text) FROM eq_device_profiles'),
            ],
        );
    }

    private function insertPlaylist(string $name, string $description): void
    {
        $this->connection->executeStatement(
            "INSERT INTO playlists (id, public_id, user_id, name, description, created_at, updated_at) VALUES (gen_random_uuid(), 'bounds0000000stored01', ?, ?, ?, now(), now())",
            [self::USER, $name, $description],
        );
    }

    private function migrate(AbstractMigration $migration, string $direction): void
    {
        $migration->{$direction}(new Schema());
        $this->connection->transactional(static function (Connection $connection) use ($migration): void {
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        });
    }

    /** @return array<string, bool> constraint name => validated */
    private function constraintsValidated(): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT conname, convalidated FROM pg_constraint WHERE connamespace = ?::regnamespace AND conname IN (?) ORDER BY conname',
            [$this->schema, self::CONSTRAINTS],
            [1 => ArrayParameterType::STRING],
        );

        return array_map(static fn (mixed $validated): bool => (bool) $validated, $rows);
    }

    private static function incompressible(int $bytes): string
    {
        return random_bytes(intdiv($bytes, 2) + 1) |> bin2hex(...);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
