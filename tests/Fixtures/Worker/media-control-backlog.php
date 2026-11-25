<?php

declare(strict_types=1);
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Envelope;

$project = dirname(__DIR__, 3);
require $project . '/vendor/autoload.php';
require $project . '/tests/Fixtures/Messaging/MessageCodecFactory.php';
$transport = new RedisTransport(
    Connection::fromDsn('redis://default:test-only-redis-password@redis:6379/messages/baander'),
    MessageCodecFactory::signingSerializer(),
);
$redis = new Redis();
$redis->connect('redis', 6379, 1);
$redis->auth(['default', 'test-only-redis-password']);
$deadline = hrtime(true) + 240_000_000_000;
$sent = 0;
while (hrtime(true) < $deadline) {
    $needed = max(0, 2000 - $redis->xLen('messages'));
    for ($index = 0; $index < $needed; ++$index) {
        $transport->send(new Envelope(new RelayOutboxCommand()));
        ++$sent;
    }
    $depth = $redis->xLen('messages');
    echo json_encode(['monotonic' => hrtime(true) / 1e9, 'receiverDepth' => $depth, 'sent' => $sent, 'acknowledged' => $sent - $depth], JSON_THROW_ON_ERROR) . "\n";
    flush();
    if (($stopFile = getenv('BAANDER_TEST_SAMPLER_STOP_FILE')) !== false && is_file($stopFile)) {
        break;
    }
    usleep(100000);
}
