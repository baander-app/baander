<?php

declare(strict_types=1);

/*
 * Child process of LibraryScanClaimTest: claims a library for a scan, or for a delete with files,
 * with a new claim ID over its own PostgreSQL session, so it can wait on the row lock or the
 * import lock the test process holds.
 *
 * Arguments: schema, application name, library ID, and optionally the claim kind (`scan`, the
 * default, or `delete`). Prints the outcome as JSON: whether it claimed the library and, when it
 * did not, the kind of claim that holds it.
 */

use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Library\LibraryScanClaimRace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 4 && count($arguments) !== 5) {
    throw new InvalidArgumentException('Expected three or four arguments.');
}
[, $schema, $application, $libraryId] = array_map(strval(...), $arguments);
$kind = (string) ($arguments[4] ?? 'scan');

$connection = LibraryScanClaimRace::connect($schema, $application);
// Bounds a wait the test does not release; the test fails on the resulting error.
$connection->executeStatement("SET lock_timeout = '10s'");

$libraries = LibraryScanClaimRace::repository(LibraryScanClaimRace::entityManager($connection));
$attempt = match ($kind) {
    'scan' => $libraries->claimScan(Uuid::fromString($libraryId), new Uuid(), 900),
    'delete' => $libraries->claimDelete(Uuid::fromString($libraryId), new Uuid(), 900),
    default => throw new InvalidArgumentException(sprintf('Unknown claim kind "%s".', $kind)),
};

echo json_encode(['claimed' => $attempt->claimed, 'holder' => $attempt->holder?->value], JSON_THROW_ON_ERROR);
