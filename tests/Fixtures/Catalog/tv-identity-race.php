<?php

declare(strict_types=1);

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Kernel;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $schema, $application, $encoded] = $_SERVER['argv'];
$payload = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$manager = $container->get('doctrine')->getManager();
assert($manager instanceof EntityManagerInterface);
$connection = $manager->getConnection();
$connection->executeStatement('SET search_path TO ' . $connection->quoteIdentifier($schema) . ', public');
$connection->executeQuery("SELECT set_config('application_name', ?, false)", [$application])->free();
$connection->executeStatement("SET lock_timeout = '20s'");
$message = new FilesDiscovered(
    Uuid::fromString($payload['library']),
    'tv_show',
    $payload['directory'],
    array_map(static fn(array $file): DiscoveredFile => new DiscoveredFile(...$file), $payload['files']),
);
$container->get(FilesDiscoveredHandler::class)($message);
echo json_encode([
    'series' => (int) $connection->fetchOne('SELECT count(*) FROM tv_series'),
    'seasons' => (int) $connection->fetchOne('SELECT count(*) FROM tv_seasons'),
    'episodes' => (int) $connection->fetchOne('SELECT count(*) FROM tv_episodes'),
], JSON_THROW_ON_ERROR);
$kernel->shutdown();
