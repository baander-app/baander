<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Yaml\Yaml;

/** Real Redis retention and ownership, using production async transport options. */
final class MessengerRetentionTest extends TestCase
{
    /** @var list<Connection> */
    private array $connections = [];
    private \Redis $redis;
    private string $dsn;
    private string $stream;
    private string $group;

    protected function setUp(): void
    {
        $dsn = getenv('MESSENGER_TEST_REDIS_DSN');
        if (!$dsn) {
            self::markTestSkipped('Set MESSENGER_TEST_REDIS_DSN to disposable Redis.');
        }
        $this->dsn = $dsn;
        $this->stream = 'messenger_retention_' . bin2hex(random_bytes(12));
        $this->group = 'test';
        $parts = parse_url($dsn);
        self::assertIsArray($parts);
        $this->redis = new \Redis();
        $this->redis->connect($parts['host'], $parts['port'] ?? 6379, 2.0);
        if (isset($parts['pass'])) {
            $auth = isset($parts['user']) ? [rawurldecode($parts['user']), rawurldecode($parts['pass'])] : rawurldecode($parts['pass']);
            $this->redis->auth($auth);
        }
        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 2.0);
        $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
    }

    public function testAcceptedPendingAndUnreadPayloadsSurviveMoreThanTheFormerStreamCap(): void
    {
        $transport = $this->transport('original');
        $message = new ExtractAlbumCoverCommand(Uuid::v4());
        $envelope = new Envelope($message);
        $accepted = [$this->sendId($transport, $envelope)];
        $pending = $this->receive($transport);
        self::assertEquals($message, $pending->getMessage());
        $accepted[] = $this->sendId($transport, $envelope);
        // Exercise Symfony Connection::add for every accepted message, including
        // the actual production cap boundary. No raw XADD substitutes its options.
        for ($number = 2; $number < 100500; ++$number) {
            $accepted[] = $this->sendId($transport, $envelope);
        }

        self::assertNotEmpty(
            $this->redis->xRange($this->stream, $accepted[0], $accepted[0]),
            'Adding new messages must not trim the payload of an unacknowledged pending delivery.',
        );
        self::assertNotEmpty(
            $this->redis->xRange($this->stream, $accepted[1], $accepted[1]),
            'Adding new messages must not trim unread deliveries.',
        );
        self::assertSame(count($accepted), $this->redis->xLen($this->stream));
        self::assertSame(count($accepted) - 1, $transport->getMessageCount());
        $retained = [];
        $after = '-';
        do {
            $entries = $this->redis->xRange($this->stream, $after, '+', 1000);
            foreach (array_keys($entries) as $id) {
                $retained[] = $id;
                $after = '(' . $id;
            }
        } while ($entries !== []);
        self::assertSame($accepted, $retained, 'Every accepted entry survives until acknowledgement.');

        $transport->ack($pending);
        self::assertSame([], $this->redis->xRange($this->stream, $accepted[0], $accepted[0]));
        $unread = $this->receive($transport);
        self::assertSame($accepted[1], $unread->last(TransportMessageIdStamp::class)?->getId());
        $transport->ack($unread);
        self::assertSame([], $this->redis->xRange($this->stream, $accepted[1], $accepted[1]));

        // The two actual transport acknowledgements verify delete_after_ack.
        // Drain the large remaining fixture in batches instead of 100k network
        // round-trips; inspect retained IDs above before any acknowledgement.
        $drained = 0;
        while ($batch = $this->redis->xReadGroup($this->group, 'batch-cleanup', [$this->stream => '>'], 1000)) {
            $ids = array_keys($batch[$this->stream] ?? []);
            if ($ids === []) {
                break;
            }
            $drained += count($ids);
            self::assertSame(count($ids), $this->redis->xAck($this->stream, $this->group, $ids));
            self::assertSame(count($ids), $this->redis->xDel($this->stream, $ids));
        }
        self::assertSame(count($accepted) - 2, $drained);
        self::assertSame(0, $this->redis->xLen($this->stream));
        self::assertSame(0, $transport->getMessageCount());
        self::assertSame(0, $this->redis->xPending($this->stream, $this->group)[0]);
    }

    public function testKeepaliveProtectsHealthyOwnerAndAbandonedDeliveryIsReclaimed(): void
    {
        // Short ownership timings are test-only; retain other production options.
        $owner = $this->transport('original', ['redeliver_timeout' => 1, 'claim_interval' => 1]);
        $message = new ExtractAlbumCoverCommand(Uuid::v4());
        $id = $this->sendId($owner, new Envelope($message));
        $pending = $this->receive($owner);
        $this->agePending($id);
        $owner->keepalive($pending, 1);
        $peer = $this->transport('healthy-peer', ['redeliver_timeout' => 1, 'claim_interval' => 1]);
        self::assertSame([], iterator_to_array($peer->get()), 'A peer must not reclaim a kept-alive delivery.');
        self::assertSame('original', $this->redis->xPending($this->stream, $this->group, '-', '+', 1)[0][1]);

        $this->agePending($id);
        $owner->close(); // Original consumer disappears without acknowledgement.
        $replacement = $this->transport('replacement', ['redeliver_timeout' => 1, 'claim_interval' => 1]);
        $reclaimed = $this->receive($replacement);
        self::assertEquals($message, $reclaimed->getMessage());
        self::assertSame($id, $reclaimed->last(TransportMessageIdStamp::class)?->getId());
        self::assertSame('replacement', $this->redis->xPending($this->stream, $this->group, '-', '+', 1)[0][1]);
        $replacement->ack($reclaimed);
        self::assertSame(0, $this->redis->xLen($this->stream));
        self::assertSame(0, $this->redis->xPending($this->stream, $this->group)[0]);
    }

    private function agePending(string $id): void
    {
        // Set idle time deterministically; no multi-second scheduling assumptions.
        $this->redis->rawCommand('XCLAIM', $this->stream, $this->group, 'original', 0, $id, 'IDLE', 2000, 'JUSTID');
    }

    private function sendId(RedisTransport $transport, Envelope $envelope): string
    {
        $stamp = $transport->send($envelope)->last(TransportMessageIdStamp::class);
        if ($stamp === null) {
            throw new \LogicException('Accepted message must have a transport ID.');
        }
        return $stamp->getId();
    }

    private function receive(RedisTransport $transport): Envelope
    {
        // A newly created group first checks its empty pending list.
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            foreach ($transport->get() as $envelope) {
                return $envelope;
            }
        }
        self::fail('Expected an available delivery within three receives.');
    }

    /** @param array<string, mixed> $overrides */
    private function transport(string $consumer, array $overrides = []): RedisTransport
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/packages/messenger.yaml');
        // Read the production block explicitly, excluding the in-memory test override.
        $options = $config['framework']['messenger']['transports']['async']['options'];
        $options = array_replace($options, ['stream' => $this->stream, 'group' => $this->group, 'consumer' => $consumer, 'timeout' => 2.0, 'read_timeout' => 2.0], $overrides);
        $connection = Connection::fromDsn($this->dsn, $options);
        $this->connections[] = $connection;
        $transport = new RedisTransport($connection, new JsonTransportSerializer(MessageCodecFactory::create()));
        $transport->setup();
        return $transport;
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->cleanup();
            $connection->close();
        }
        if (isset($this->redis)) {
            $this->redis->close();
        }
    }
}
