<?php

declare(strict_types=1);

use App\Transcode\Application\DTO\MediaWorkerIdentity;
use App\Transcode\Infrastructure\Doctrine\AudioRenditionRuntime;
use App\Transcode\Infrastructure\Transcode\AudioRenditionExecutor;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Psr\Log\NullLogger;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $schema, $encoder] = $_SERVER['argv'];
if (preg_match('/^media_runtime_[a-f0-9]{16}$/D', $schema) !== 1) {
    throw new RuntimeException('Invalid disposable schema.');
}
$url = getenv('OUTBOX_TEST_DATABASE_URL') ?: throw new RuntimeException('Missing disposable database.');
$connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
$connection->executeStatement('SET search_path TO ' . $schema . ', public');
$runtime = new AudioRenditionRuntime($connection);
$deadline = microtime(true) + 5;
while (microtime(true) < $deadline) {
    foreach ($runtime->pending() as $pending) {
        $claim = $runtime->claim($pending->key, new MediaWorkerIdentity('baander.app:test', str_repeat('b', 32), 'media', 1));
        if ($claim !== null) {
            (new AudioRenditionExecutor($runtime, new NullLogger(), $encoder))->run($claim, static fn (): bool => false);
            $connection->close();
            exit(0);
        }
    }
    usleep(10_000);
}
throw new RuntimeException('No durable audio admission became visible.');
