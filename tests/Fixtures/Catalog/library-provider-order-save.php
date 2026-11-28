<?php

declare(strict_types=1);

use App\Catalog\Domain\Model\LibraryProviderOrder;
use App\Catalog\Infrastructure\Doctrine\Repository\LibraryProviderOrderRepository;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $schema, $application, $library] = $_SERVER['argv'];
$connection = ArtistImportIdentityRace::connect($schema, $application);
$connection->executeStatement("SET lock_timeout = '15s'");
$orders = new LibraryProviderOrderRepository($connection);
$orders->save(LibraryProviderOrder::fromProviders(Uuid::fromString($library), ['local', 'tmdb'], ['tmdb', 'local']));
echo json_encode(['saved' => true], JSON_THROW_ON_ERROR);
