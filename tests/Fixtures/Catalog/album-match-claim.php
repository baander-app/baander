<?php

declare(strict_types=1);

/*
 * Child process of AlbumMatchClaimRaceTest: a second PostgreSQL session that can wait on a row the
 * test process changed and has not committed.
 *
 * Arguments: schema, application name, step and a JSON payload.
 * - "matched" {"album", "claim"}: ends the claim on a matched album, as the queue run does, and
 *   prints {"left": {"status", "source", "dueAt"}}, or {"left": null} when the album has no
 *   request any more.
 * - "request" {"album", "source", "dueAt"}: asks for the album's match and prints {"dueSooner": bool}.
 */

use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\AlbumMatchClaimRace;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 5) {
    throw new InvalidArgumentException('Expected four arguments.');
}
[, $schema, $application, $step, $payload] = array_map(strval(...), $arguments);
$payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

$connection = ArtistImportIdentityRace::connect($schema, $application);
// Bounds a wait the test does not release; the test fails on the resulting error.
$connection->executeStatement("SET lock_timeout = '10s'");
$states = AlbumMatchClaimRace::states($connection);
$album = Uuid::fromString($payload['album']);

echo json_encode(match ($step) {
    'matched' => ['left' => $states->removeClaimedRequest($album, Uuid::fromString($payload['claim'])) |> AlbumMatchClaimRace::described(...)],
    'request' => ['dueSooner' => $states->request($album, AlbumMatchSource::from($payload['source']), new DateTimeImmutable($payload['dueAt']))],
    default => throw new InvalidArgumentException(sprintf('Unknown step "%s".', $step)),
}, JSON_THROW_ON_ERROR);
