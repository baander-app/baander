<?php

declare(strict_types=1);

/*
 * Child process of ArtistSyncRaceTest: a second PostgreSQL session that can wait on the locks the
 * test process holds.
 *
 * Arguments: schema, application name and a JSON payload {"artistId", "mbid", "name", "country",
 * "type", "sortName"}. Syncs the artist through the production handler, with MusicBrainz answering
 * the ID with that record, and prints {"outcome": "synced"}.
 */

use App\Metadata\Application\Message\SyncArtistMessage;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\ArtistSyncRace;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\FakeMusicBrainzLookup;

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
$lookup = new FakeMusicBrainzLookup();
$lookup->artists[$payload['mbid']] = ArtistSyncRace::answer($payload['mbid'], $payload['name'], $payload['country'], $payload['type'], $payload['sortName']);

ArtistSyncRace::handler(ArtistImportIdentityRace::entityManager($connection), $lookup)(new SyncArtistMessage(new Uuid($payload['artistId'])));

echo json_encode(['outcome' => 'synced'], JSON_THROW_ON_ERROR);
