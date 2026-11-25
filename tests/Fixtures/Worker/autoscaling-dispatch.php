<?php

declare(strict_types=1);
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use App\Tests\Fixtures\Worker\Autoscaling\Workload;
use App\Tests\Fixtures\Worker\Autoscaling\WorkloadCodec;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Envelope;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$batch = $argv[1] ?? '';
$count = filter_var($argv[2] ?? '48', FILTER_VALIDATE_INT);
$kind = $argv[3] ?? 'io';
if ($count === false || $count < 1 || $count > 48) { throw new InvalidArgumentException('Invalid bounded workload count.'); }
new Workload($batch, 0, $kind);
$transport = new RedisTransport(
    Connection::fromDsn('redis://default:test-only-redis-password@redis:6379/messages/baander'),
    MessageCodecFactory::signingSerializer([new WorkloadCodec()]),
);
$started = hrtime(true) / 1e9;
for ($index = 0; $index < $count; ++$index) {
    $transport->send(new Envelope(new Workload($batch, $index, $kind)));
}
echo json_encode(['batch' => $batch, 'count' => $count, 'kind' => $kind, 'started' => $started, 'published' => hrtime(true) / 1e9], JSON_THROW_ON_ERROR) . "\n";
