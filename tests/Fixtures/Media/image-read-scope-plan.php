<?php

declare(strict_types=1);

/**
 * Run in the migrated disposable functional container:
 * php tests/Fixtures/Media/image-read-scope-plan.php
 *
 * Captures the production repository's emitted SQL and replays EXPLAIN ANALYZE.
 * Cloned tables preserve migration indexes; all fixture data lives in a temporary
 * schema. Planner settings, including JIT, remain unchanged. Times are evidence,
 * not portable pass/fail thresholds. Active artist sequential scans are rejected
 * for this selective-library fixture; review other workloads separately.
 */
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Media\Infrastructure\Doctrine\Repository\ImageRepository;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\ValueObject\MediaReadScope;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Psr\Log\AbstractLogger;

/** @param array<string, mixed> $node */
function assertBoundedArtistScan(array $node): void
{
    if (($node['Relation Name'] ?? null) === 'artists'
        && ($node['Node Type'] ?? null) === 'Seq Scan'
        && ($node['Actual Loops'] ?? 0) > 0
    ) {
        throw new RuntimeException('Selective image read scanned the entire artist catalog.');
    }
    /** @var list<array<string, mixed>> $children */
    $children = $node['Plans'] ?? [];
    foreach ($children as $child) {
        assertBoundedArtistScan($child);
    }
}

$url = getenv('OUTBOX_TEST_DATABASE_URL');
if ($url === false || $url === '') {
    throw new RuntimeException('Run with the disposable PostgreSQL functional runner.');
}
CustomTypesRegistrar::register();
$log = new class extends AbstractLogger {
    /** @var list<array{sql: string, params: array<int, mixed>}> */
    public array $queries = [];

    /** @return array{sql: string, params: array<int, mixed>}|false */
    public function lastQuery(): array|false
    {
        return end($this->queries);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql'], $context['params'])) {
            /** @var array{sql: string, params: array<int, mixed>} $context */
            $this->queries[] = $context;
        }
    }
};
$dbal = new Configuration();
$dbal->setMiddlewares([new BaanderDriverMiddleware(), new Middleware($log)]);
$params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
$connection = DriverManager::getConnection($params, $dbal);
$schema = 'image_read_plan_' . bin2hex(random_bytes(8));
$connection->executeStatement('CREATE SCHEMA ' . $schema);
try {
    $connection->executeStatement('SET search_path TO ' . $schema . ', public');
    foreach (['users', 'libraries', 'albums', 'artists', 'images', 'songs', 'artist_album', 'artist_song', 'playlists'] as $table) {
        $connection->executeStatement('CREATE TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL)');
    }
    $orm = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src'], isDevMode: true);
    $orm->setNamingStrategy(new UnderscoreNamingStrategy());
    $orm->enableNativeLazyObjects(true);
    $manager = new EntityManager($connection, $orm);
    $actor = new UserEntity(new PublicId(), 'Plan Actor', 'image-plan@baander.app', 'test-only', '');
    $allowed = new LibraryEntity('Allowed', 'plan-allowed', '/plan/allowed', 'music', 'local');
    $denied = new LibraryEntity('Denied', 'plan-denied', '/plan/denied', 'music', 'local');
    $album = new AlbumEntity(new PublicId(), $allowed, 'Reverse Album', 'album');
    $artist = new ArtistEntity(new PublicId(), 'Reverse Artist');
    $playlist = new PlaylistEntity(new PublicId(), $actor, 'Owned');
    $images = [];
    foreach (['album', 'artist', 'album-direct', 'artist-direct', 'playlist', 'denied'] as $kind) {
        $images[$kind] = new ImageEntity('/plan/' . $kind . '.webp', 'webp', 'image/webp', new PublicId(), 32, 2, 2, 'album');
    }
    $album->setCoverImage($images['album']);
    $artist->setCoverImage($images['artist']);
    $images['album-direct']->setAlbum($album);
    $images['artist-direct']->setArtist($artist);
    $images['playlist']->setPlaylist($playlist);
    foreach ([$actor, $allowed, $denied, $album, $artist, $playlist,
        ...array_values($images), new ArtistAlbumEntity($artist, $album, 'primary'),
    ] as $entity) {
        $manager->persist($entity);
    }
    $manager->flush();
    $manager->clear();
    $connection->executeStatement(<<<'SQL'
        INSERT INTO albums (id, public_id, library_id, title, type, created_at, updated_at)
        SELECT gen_random_uuid(), substr(translate(md5('album' || n), '0', 'A'), 1, 21),
            :library, 'Bulk Album ' || n, 'album', now(), now()
        FROM generate_series(1, 5000) n
        SQL, ['library' => $denied->getId()->toString()]);
    $connection->executeStatement(<<<'SQL'
        INSERT INTO artists (id, public_id, name, created_at, updated_at)
        SELECT gen_random_uuid(), substr(translate(md5('artist' || n), '0', 'A'), 1, 21),
            'Bulk Artist ' || n, now(), now()
        FROM generate_series(1, 5000) n
        SQL);
    $connection->executeStatement(<<<'SQL'
        INSERT INTO images (
            id, public_id, path, extension, mime_type, size, width, height,
            imageable_type, created_at, updated_at
        )
        SELECT gen_random_uuid(), substr(translate(md5('image' || n), '0', 'A'), 1, 21),
            '/plan/dummy.webp', 'webp', 'image/webp', 32, 2, 2, 'album', now(), now()
        FROM generate_series(1, 5000) n
        SQL);
    foreach (['images', 'albums', 'artists', 'artist_album', 'artist_song', 'songs', 'playlists'] as $table) {
        $connection->executeStatement('ANALYZE ' . $table);
    }
    echo json_encode([
        'server' => $connection->fetchOne('SHOW server_version'),
        'jit' => $connection->fetchOne('SHOW jit'),
        'extensions' => $connection->fetchAllAssociative('SELECT extname, extversion FROM pg_extension ORDER BY extname'),
    ], JSON_THROW_ON_ERROR) . "\n";
    $repository = new ImageRepository($manager);
    $scope = MediaReadScope::authenticated($actor->getId(), LibraryReadScope::restricted([$allowed->getId()]));
    foreach ($images as $kind => $image) {
        $log->queries = [];
        $view = $repository->findVisibleByPublicId($image->getPublicId(), $scope);
        if (($view !== null) !== ($kind !== 'denied')) {
            throw new RuntimeException('Unexpected authorization: ' . $kind);
        }
        $entry = $log->lastQuery();
        if ($entry === false) {
            throw new RuntimeException('The actual repository SQL was not captured.');
        }
        $json = $connection->executeQuery(
            'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) ' . $entry['sql'],
            array_values($entry['params']),
        )->fetchOne();
        /** @var list<array{Plan: array<string, mixed>}> $plan */
        $plan = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        assertBoundedArtistScan($plan[0]['Plan']);
        echo json_encode(['case' => $kind, 'allowed' => $view !== null, 'explain' => $plan[0]], JSON_THROW_ON_ERROR) . "\n";
    }
} finally {
    $connection->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
    $connection->close();
}
