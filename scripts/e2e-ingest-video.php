<?php

declare(strict_types=1);

/**
 * Ingest the Big Buck Bunny test video into the catalog for e2e transcoding tests.
 *
 * This script bypasses the Messenger transports so it can be run from the CLI
 * while the Swoole server is running. It uses the same Catalog ingest service as
 * app:e2e:ingest-video, so the resulting Video/Movie records are identical to a
 * normal library scan.
 */

use App\Catalog\Application\Service\MovieLibraryIngest;
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();

/** @var MovieLibraryIngest $ingest */
$ingest = $kernel->getContainer()->get(MovieLibraryIngest::class);

$result = $ingest->ingestExistingLibrary('e2e-test-movies');

if ($result === null) {
    fwrite(STDERR, "Library 'e2e-test-movies' not found. Create it first with:\n");
    fwrite(STDERR, "  php bin/console app:library:create 'E2E Test Movies' /home/martin/Videos movie\n");
    exit(1);
}

echo json_encode([
    'libraryId' => $result->libraryId,
    'videoIds' => $result->videoIds,
], JSON_PRETTY_PRINT) . "\n";
