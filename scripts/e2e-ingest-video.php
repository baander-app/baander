<?php

declare(strict_types=1);

/**
 * Ingest the Big Buck Bunny test video into the catalog for e2e transcoding tests.
 *
 * This script bypasses the Messenger transports so it can be run from the CLI
 * while the Swoole server is running. It uses the real domain handlers, so the
 * resulting Video/Movie records are identical to a normal library scan.
 */

use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Kernel;
use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\CommandHandler\FilesDiscoveredHandler;
use App\Library\Application\CommandHandler\ScanLibraryHandler;
use App\Library\Application\MovieScanner;
use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibrarySlug;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();

$container = $kernel->getContainer();

$libraryRepository = $container->get(LibraryRepositoryInterface::class);
$scanHandler = $container->get(ScanLibraryHandler::class);
$catalogHandler = $container->get(FilesDiscoveredHandler::class);
$movieScanner = $container->get(MovieScanner::class);
$videoRepository = $container->get(VideoRepositoryInterface::class);

$slug = new LibrarySlug('e2e-test-movies');
$library = $libraryRepository->findBySlug($slug);

if ($library === null) {
    fwrite(STDERR, "Library 'e2e-test-movies' not found. Create it first with:\n");
    fwrite(STDERR, "  php bin/console app:library:create 'E2E Test Movies' /home/martin/Videos movie\n");
    exit(1);
}

// Run the scan synchronously. This would normally emit FilesDiscovered messages
// to the async transport; we handle them directly below.
$scanHandler(new ScanLibraryCommand(librarySlug: $slug));

$library = $libraryRepository->findBySlug($slug);
if ($library === null) {
    fwrite(STDERR, "Library disappeared during scan.\n");
    exit(1);
}

// Directly invoke the catalog handler for each discovered directory.
$scanResult = $movieScanner->scan($library, true);

$videoIds = [];
foreach ($scanResult->directories as $directory => $files) {
    $catalogHandler(new \App\Library\Application\Message\FilesDiscovered(
        libraryId: $library->getId(),
        libraryType: $library->getType()->value,
        directory: $directory,
        files: $files,
    ));

    foreach ($files as $file) {
        $video = $videoRepository->findByHash($file->hash);
        if ($video !== null) {
            $videoIds[] = $video->getId()->toString();
        }
    }
}

echo json_encode([
    'libraryId' => $library->getId()->toString(),
    'videoIds' => $videoIds,
], JSON_PRETTY_PRINT) . "\n";
