<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$batch = $argv[1] ?? '';
$count = filter_var($argv[2] ?? '48', FILTER_VALIDATE_INT);
$kind = $argv[3] ?? 'io';
if ($count === false || $count < 1 || $count > 48) { throw new InvalidArgumentException('Invalid bounded workload count.'); }
new App\Tests\Fixtures\Worker\Autoscaling\Workload($batch, 0, $kind);
$transport = new Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport(
    Symfony\Component\Messenger\Bridge\Redis\Transport\Connection::fromDsn('redis://default:test-only@redis:6379/messages/baander'),
    new App\Shared\Infrastructure\Messenger\JsonTransportSerializer(new App\Shared\Infrastructure\Messaging\JsonMessageCodec([
        new App\Tests\Fixtures\Worker\Autoscaling\WorkloadCodec(),
    ])),
);
$started = hrtime(true) / 1e9;
for ($index = 0; $index < $count; ++$index) {
    $transport->send(new Symfony\Component\Messenger\Envelope(new App\Tests\Fixtures\Worker\Autoscaling\Workload($batch, $index, $kind)));
}
echo json_encode(['batch' => $batch, 'count' => $count, 'kind' => $kind, 'started' => $started, 'published' => hrtime(true) / 1e9], JSON_THROW_ON_ERROR) . "\n";
