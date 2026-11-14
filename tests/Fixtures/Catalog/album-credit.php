<?php

declare(strict_types=1);

/*
 * Child process of AlbumCreditRaceTest: a second import deciding an album's artist credit over its
 * own PostgreSQL session, so it can wait on the album row the test process holds locked.
 *
 * Arguments: schema, application name and a JSON payload [album ID, album artist], where the album
 * artist is null or [tagged name, MusicBrainz IDs, credited name]. It decides the credit in one
 * transaction and prints {"decided": true}.
 */

use App\Tests\Fixtures\Catalog\AlbumCreditRace;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 4) {
    throw new InvalidArgumentException('Expected three arguments.');
}
[, $schema, $application, $payload] = array_map(strval(...), $arguments);
[$albumId, $albumArtist] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

$connection = ArtistImportIdentityRace::connect($schema, $application);
// Bounds a wait the test does not release; the test fails on the resulting error.
$connection->executeStatement("SET lock_timeout = '10s'");

$connection->beginTransaction();
AlbumCreditRace::decide(ArtistImportIdentityRace::entityManager($connection), (string) $albumId, $albumArtist);
$connection->commit();

echo json_encode(['decided' => true], JSON_THROW_ON_ERROR);
