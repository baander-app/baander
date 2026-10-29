<?php

declare(strict_types=1);

$project = dirname(__DIR__, 3);
require $project . '/vendor/autoload.php';
require $project . '/tests/Fixtures/Messaging/MessageCodecFactory.php';
$transport = new Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport(
    Symfony\Component\Messenger\Bridge\Redis\Transport\Connection::fromDsn('redis://default:test-only@redis:6379/messages/baander'),
    new App\Shared\Infrastructure\Messenger\JsonTransportSerializer(App\Tests\Fixtures\Messaging\MessageCodecFactory::create()),
);
$redis = new Redis(); $redis->connect('redis', 6379, 1); $redis->auth(['default', 'test-only']);
$deadline = hrtime(true) + 240_000_000_000;
$sent = 0;
while (hrtime(true) < $deadline) {
    $needed = max(0, 2000 - $redis->xLen('messages'));
    for ($index = 0; $index < $needed; ++$index) {
        $transport->send(new Symfony\Component\Messenger\Envelope(new App\Shared\Domain\Event\Outbox\RelayOutboxCommand()));
        ++$sent;
    }
    $depth = $redis->xLen('messages');
    echo json_encode(['monotonic' => hrtime(true) / 1e9, 'receiverDepth' => $depth, 'sent' => $sent, 'acknowledged' => $sent - $depth], JSON_THROW_ON_ERROR) . "\n";
    flush();
    if (($stopFile = getenv('BAANDER_TEST_SAMPLER_STOP_FILE')) !== false && is_file($stopFile)) { break; }
    usleep(100000);
}
