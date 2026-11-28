<?php

declare(strict_types=1);

use App\Library\Application\Message\DiscoveredFile;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\MovieIdentityRace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $schema, $application, $library, $json] = $_SERVER['argv'];
$file = new DiscoveredFile(...json_decode($json, true, flags: JSON_THROW_ON_ERROR));
$connection = ArtistImportIdentityRace::connect($schema, $application);
$connection->executeStatement("SET lock_timeout = '15s'");
$manager = ArtistImportIdentityRace::entityManager($connection);
$connection->transactional(static function () use ($manager, $library, $file): void {
    MovieIdentityRace::import($manager, Uuid::fromString($library), [$file]);
});
$id = $connection->fetchOne(
    'SELECT p.movie_id FROM video_placements p JOIN videos v ON v.id = p.video_id WHERE p.library_id = ? AND v.hash = ?',
    [$library, $file->hash],
);
echo json_encode($id, JSON_THROW_ON_ERROR);
