<?php

declare(strict_types=1);

/*
 * Child process of ArtistImportIdentityRaceTest: a second import over its own PostgreSQL session,
 * so it can wait on the import identity locks the test process holds.
 *
 * Arguments: schema, application name, mode and a JSON payload. `resolve` takes a list of
 * [tagged name, MusicBrainz IDs] pairs, resolves them in one transaction and prints
 * {"artists": [artist ID of each pair]}. `lock` takes a list of lock keys, locks them in one
 * transaction and prints {"locked": true}.
 */

use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;

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

$connection->beginTransaction();
$result = match ($mode) {
    'resolve' => (static function () use ($connection, $payload): array {
        $identities = ArtistImportIdentityRace::identities($payload);
        $artists = ArtistImportIdentityRace::resolver(ArtistImportIdentityRace::entityManager($connection))->resolve($identities);

        return ['artists' => array_map(static fn (ArtistIdentity $identity): string => $artists[$identity->key()]->toString(), $identities)];
    })(),
    'lock' => (static function () use ($connection, $payload): array {
        ImportIdentityLock::acquire($connection, $payload);

        return ['locked' => true];
    })(),
    default => throw new InvalidArgumentException(sprintf('Unknown mode "%s".', $mode)),
};
$connection->commit();

echo json_encode($result, JSON_THROW_ON_ERROR);
