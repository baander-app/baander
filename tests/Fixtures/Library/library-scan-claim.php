<?php

declare(strict_types=1);

/*
 * Child process of LibraryScanClaimTest: claims a library for a scan over its own PostgreSQL
 * session, so it can wait on the row lock the test process holds.
 *
 * Arguments: schema, application name, library ID. Prints the outcome as JSON.
 */

use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Library\LibraryScanClaimRace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 4) {
    throw new InvalidArgumentException('Expected three arguments.');
}
[, $schema, $application, $libraryId] = array_map(strval(...), $arguments);

$connection = LibraryScanClaimRace::connect($schema, $application);
// Bounds a wait the test does not release; the test fails on the resulting error.
$connection->executeStatement("SET lock_timeout = '10s'");

echo json_encode([
    'claimed' => LibraryScanClaimRace::repository(LibraryScanClaimRace::entityManager($connection))->claimScan(Uuid::fromString($libraryId)),
], JSON_THROW_ON_ERROR);
