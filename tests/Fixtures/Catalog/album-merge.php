<?php

declare(strict_types=1);

/*
 * Child process of AlbumMergeRaceTest: a second PostgreSQL session that can wait on the locks the
 * test process holds.
 *
 * Arguments: schema, application name, mode and a JSON payload.
 * - "merge" {"target", "source"}: merges the source album into the target through the production
 *   merge and prints {"merged": ID}, {"notFound": message} or {"conflict": reason}.
 * - "insert" {"album"}: stores a song of the album in a transaction of its own, as an import does,
 *   and prints {"inserted": ID}, or the SQLSTATE of the failure as {"sqlstate": "..."}.
 */

use App\Tests\Fixtures\Catalog\AlbumMergeRace;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Exception\DriverException;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 5) {
    throw new InvalidArgumentException('Expected four arguments.');
}
[, $schema, $application, $mode, $payload] = array_map(strval(...), $arguments);
$payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

$connection = ArtistImportIdentityRace::connect($schema, $application);
// Bounds a wait the test does not release; the test fails on the resulting error.
$connection->executeStatement("SET lock_timeout = '10s'");

if ($mode === 'merge') {
    echo json_encode(AlbumMergeRace::merge(ArtistImportIdentityRace::entityManager($connection), $payload['target'], $payload['source']), JSON_THROW_ON_ERROR);

    return;
}

$connection->beginTransaction();
try {
    $song = AlbumMergeRace::insertSong($connection, $payload['album'], 'Here Comes The Sun', 'here-comes-the-sun-' . $application);
    $connection->commit();
    echo json_encode(['inserted' => $song], JSON_THROW_ON_ERROR);
} catch (DriverException $exception) {
    $connection->rollBack();
    echo json_encode(['sqlstate' => $exception->getSQLState()], JSON_THROW_ON_ERROR);
}
