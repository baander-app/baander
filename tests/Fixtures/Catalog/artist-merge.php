<?php

declare(strict_types=1);

/*
 * Child process of ArtistMergeRaceTest: a second PostgreSQL session that can wait on the artist
 * rows the test process holds locked.
 *
 * Arguments: schema, application name, mode, artist ID and song ID. In mode "credit" it credits
 * the artist on the song as featured in one transaction and prints {"credited": true}, or the
 * SQLSTATE of the failure as {"sqlstate": "..."}. In mode "lock" it locks the artist through the
 * merge port in one transaction and prints what lock() returned.
 */

use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\ArtistMergeRace;
use Doctrine\DBAL\Exception\DriverException;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 6) {
    throw new InvalidArgumentException('Expected five arguments.');
}
[, $schema, $application, $mode, $artistId, $songId] = array_map(strval(...), $arguments);

$connection = ArtistImportIdentityRace::connect($schema, $application);
// Bounds a wait the test does not release; the test fails on the resulting error.
$connection->executeStatement("SET lock_timeout = '10s'");

$connection->beginTransaction();
if ($mode === 'lock') {
    $merges = ArtistImportIdentityRace::entityManager($connection) |> ArtistMergeRace::merges(...);
    $locked = $merges->lock([Uuid::fromString($artistId)]);
    $connection->commit();
    echo json_encode($locked, JSON_THROW_ON_ERROR);

    return;
}

try {
    $connection->executeStatement(
        "INSERT INTO artist_song (id, artist_id, song_id, role, credited_name) VALUES (:id, :artistId, :songId, 'featured', 'Guest')",
        ['id' => (new Uuid())->toString(), 'artistId' => $artistId, 'songId' => $songId],
    );
    $connection->commit();
    echo json_encode(['credited' => true], JSON_THROW_ON_ERROR);
} catch (DriverException $exception) {
    $connection->rollBack();
    echo json_encode(['sqlstate' => $exception->getSQLState()], JSON_THROW_ON_ERROR);
}
