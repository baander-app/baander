<?php

declare(strict_types=1);

/*
 * Child process of RetagLockOrderTest: takes a retag's locks over its own PostgreSQL session, so the
 * test can see which locks it holds while it waits on one the test process holds.
 *
 * Arguments: schema, application name and a JSON payload {"artists": [[tagged name, MusicBrainz
 * IDs], ...], "genres": [tagged name, ...], "songs": [song ID, ...]}. It locks them as a retag does
 * in one transaction, commits and prints {"locked": true}.
 */

use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\RetagLockRace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 4) {
    throw new InvalidArgumentException('Expected three arguments.');
}
[, $schema, $application, $payload] = array_map(strval(...), $arguments);
$payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

$connection = ArtistImportIdentityRace::connect($schema, $application);
// Bounds a wait the test does not release; the test fails on the resulting error.
$connection->executeStatement("SET lock_timeout = '10s'");
// Without index scans the songs are read in table order, so they are locked in ID order only
// because the lock sorts them.
$connection->executeStatement('SET enable_indexscan = off');
$connection->executeStatement('SET enable_indexonlyscan = off');

$connection->beginTransaction();
RetagLockRace::songs($connection)->lockForRetag(
    ArtistImportIdentityRace::identities($payload['artists']),
    $payload['genres'],
    array_map(static fn (string $id): Uuid => new Uuid($id), $payload['songs']),
);
$connection->commit();

echo json_encode(['locked' => true], JSON_THROW_ON_ERROR);
