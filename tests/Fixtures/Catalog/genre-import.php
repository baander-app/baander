<?php

declare(strict_types=1);

/*
 * Child process of GenreImportRaceTest: a second import over its own PostgreSQL session, so it
 * can wait on the locks the test process holds.
 *
 * Arguments: schema, application name and a JSON list of genre names. It imports the names in one
 * transaction and prints {"genres": [genre ID of each name]}.
 */

use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\GenreImportRace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 4) {
    throw new InvalidArgumentException('Expected three arguments.');
}
[, $schema, $application, $names] = array_map(strval(...), $arguments);
$names = json_decode($names, true, flags: JSON_THROW_ON_ERROR);

$connection = ArtistImportIdentityRace::connect($schema, $application);
// Bounds a wait the test does not release; the test fails on the resulting error.
$connection->executeStatement("SET lock_timeout = '10s'");

$connection->beginTransaction();
$genres = GenreImportRace::import(ArtistImportIdentityRace::entityManager($connection), $names);
$connection->commit();

echo json_encode(['genres' => array_map(static fn (string $name): string => $genres[$name], $names)], JSON_THROW_ON_ERROR);
