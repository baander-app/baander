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
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Yaml\Yaml;

/** Real Redis failures at both sides of delayed-to-stream promotion. */
final class MessengerDelayedPromotionTest extends TestCase
{
    /** @var list<Connection> */
    private array $connections = [];
    /** @var list<string> */
    private array $aclUsers = [];
    private \Redis $redis;
    private JsonTransportSerializer $serializer;
    private string $dsn;
    private string $stream;
    private string $queue;

    protected function setUp(): void
    {
        $dsn = getenv('MESSENGER_TEST_REDIS_DSN');
        if (!$dsn) {
            self::markTestSkipped('Set MESSENGER_TEST_REDIS_DSN to disposable Redis with ACL administration.');
        }
        $this->dsn = $dsn;
        $this->stream = 'delayed_promotion_' . bin2hex(random_bytes(12));
        $this->queue = $this->stream . '__queue';
        $this->serializer = new JsonTransportSerializer(MessageCodecFactory::create());
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

    public function testFailedStreamWriteRetainsAcceptedDelayedPayloadForRecovery(): void
    {
        $transport = $this->transport();
        $original = $this->delayedEnvelope();
        $transport->send($original);
        $member = $this->onlyMember();
        $this->makeDue($member);
        // Corrupt only this fixture's empty stream after transport setup; the
        // separate delayed sorted set remains intact until promotion runs.
        $this->redis->del($this->stream);
        $this->redis->set($this->stream, 'wrong-type-fixture');
        try {
            iterator_to_array($transport->get());
            self::fail('Actual XADD must fail against the wrong-type stream.');
        } catch (TransportException $error) {
            self::assertStringContainsString('WRONGTYPE', $error->getMessage());
        }
        self::assertSame([$member], $this->members(), 'An accepted retry must remain queued when XADD fails.');

        $this->redis->del($this->stream);
        $transport->setup();
        $received = $this->receive($transport);
        $this->assertPayloadPreserved($original, $received);
        self::assertSame([], $this->members());
        $transport->ack($received);
        self::assertSame(0, $this->redis->xLen($this->stream));
    }

    public function testInterruptedRemovalRetainsRetryAndAllowsDuplicateSafeRecovery(): void
    {
        $user = 'delayed_test_' . bin2hex(random_bytes(8));
        $this->aclUsers[] = $user;
        $this->redis->rawCommand('ACL', 'SETUSER', $user, 'on', '>test-only', 'resetkeys', '~' . $this->stream . '*', '+@all', '-zrem');
        $transport = $this->transport(['auth' => [$user, 'test-only']]);
        $original = $this->delayedEnvelope();
        $transport->send($original);
        $member = $this->onlyMember();
        $this->makeDue($member);
        try {
            iterator_to_array($transport->get());
            self::fail('Actual delayed removal must fail when Redis denies ZREM.');
        } catch (TransportException $error) {
            self::assertStringContainsString('NOPERM', $error->getMessage());
        }
        self::assertSame([$member], $this->members(), 'Uncertain promotion must preserve the accepted retry.');
        self::assertGreaterThanOrEqual(1, $this->redis->xLen($this->stream), 'XADD succeeded before delayed removal was interrupted.');
        $this->redis->rawCommand('ACL', 'SETUSER', $user, '+zrem');

        $deliveries = 0;
        // At-least-once promotion may duplicate a payload when a writer succeeds
        // before removal fails or competing promoters read the same due member.
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $received = iterator_to_array($transport->get());
            foreach ($received as $envelope) {
                $this->assertPayloadPreserved($original, $envelope);
                ++$deliveries;
                $transport->ack($envelope);
            }
            if ($received === []) {
                break;
            }
        }
        self::assertGreaterThanOrEqual(1, $deliveries);
        self::assertSame([], $this->members());
        self::assertSame(0, $this->redis->xLen($this->stream));
    }

    public function testFutureRetryIsNotPromotedBeforeItsDueTime(): void
    {
        $transport = $this->transport();
        $original = $this->delayedEnvelope();
        $transport->send($original);
        $member = $this->onlyMember();
        self::assertSame([], iterator_to_array($transport->get()));
        self::assertSame([$member], $this->members());
        self::assertSame(0, $this->redis->xLen($this->stream));
        $this->makeDue($member);
        $received = $this->receive($transport);
        $this->assertPayloadPreserved($original, $received);
        $transport->ack($received);
        self::assertSame([], $this->members());
        self::assertSame(0, $this->redis->xLen($this->stream));
    }

    public function testOnePollPromotesAtMostOneHundredDueRetries(): void
    {
        $transport = $this->transport();
        $original = $this->delayedEnvelope();
        for ($sent = 0; $sent < 101; ++$sent) {
            $transport->send($original);
        }
        $members = $this->members();
        self::assertCount(101, $members);
        foreach ($members as $member) {
            $this->makeDue($member);
        }

        // Exactly one receive call exercises the production promotion bound.
        // Its pending delivery still occupies an entry in the Redis stream.
        $first = iterator_to_array($transport->get());
        self::assertCount(1, $first);
        self::assertCount(1, $this->members());
        self::assertSame(100, $this->redis->xLen($this->stream));
        $this->assertPayloadPreserved($original, $first[0]);

        $second = iterator_to_array($transport->get());
        self::assertCount(1, $second);
        self::assertSame([], $this->members());
        self::assertSame(101, $this->redis->xLen($this->stream));
        $this->assertPayloadPreserved($original, $second[0]);
        $transport->ack($first[0]);
        $transport->ack($second[0]);
        self::assertSame(99, $this->redis->xLen($this->stream));
    }

    public function testMalformedQueueFixtureIsRetainedOnPromotionError(): void
    {
        $transport = $this->transport();
        // This deliberately corrupt fixture is not a serializer-produced retry.
        // Promotion must report corruption without silently discarding it.
        $member = 'not-json';
        $past = (string) ((int) floor(microtime(true) * 1000) - 1000);
        $this->redis->rawCommand('ZADD', $this->queue, $past, $member);
        try {
            iterator_to_array($transport->get());
            self::fail('Malformed delayed queue data must fail promotion.');
        } catch (TransportException $error) {
            self::assertSame('Invalid message in the Redis delay queue.', $error->getMessage());
        }
        self::assertSame([$member], $this->members());
        self::assertSame(0, $this->redis->xLen($this->stream));
    }

    private function delayedEnvelope(): Envelope
    {
        return new Envelope(new ExtractAlbumCoverCommand(Uuid::v4()), [new DelayStamp(3600000)]);
    }

    /** @return list<string> */
    private function members(): array
    {
        return $this->redis->rawCommand('ZRANGE', $this->queue, 0, -1);
    }

    private function onlyMember(): string
    {
        $members = $this->members();
        self::assertCount(1, $members);
        return $members[0];
    }

    private function makeDue(string $member): void
    {
        // Change only the existing member's score, preserving its accepted body,
        // headers and unique retry identity. Avoid clock-dependent sleeps.
        $past = (string) ((int) floor(microtime(true) * 1000) - 1000);
        $this->redis->rawCommand('ZADD', $this->queue, 'XX', $past, $member);
        self::assertSame($past, $this->redis->rawCommand('ZSCORE', $this->queue, $member));
    }

    private function assertPayloadPreserved(Envelope $original, Envelope $received): void
    {
        self::assertEquals($original->getMessage(), $received->getMessage());
        self::assertSame(3600000, $received->last(DelayStamp::class)?->getDelay());
        $id = $received->last(TransportMessageIdStamp::class)?->getId();
        self::assertNotNull($id);
        $entry = $this->redis->xRange($this->stream, $id, $id);
        self::assertCount(1, $entry);
        $stored = json_decode($entry[$id]['message'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($this->serializer->encode($original), $stored, 'Promotion preserves the original JSON body and headers.');
    }

    private function receive(RedisTransport $transport): Envelope
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            foreach ($transport->get() as $envelope) {
                return $envelope;
            }
        }
        self::fail('Expected a recoverable due delivery within three receives.');
    }

    /** @param array<string, mixed> $overrides */
    private function transport(array $overrides = []): RedisTransport
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/packages/messenger.yaml');
        $options = $config['framework']['messenger']['transports']['async']['options'];
        $options = array_replace($options, ['stream' => $this->stream, 'group' => 'test', 'consumer' => 'test', 'timeout' => 2.0, 'read_timeout' => 2.0], $overrides);
        $connection = Connection::fromDsn($this->dsn, $options);
        $this->connections[] = $connection;
        $transport = new RedisTransport($connection, $this->serializer);
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
            foreach ($this->aclUsers as $user) {
                $this->redis->rawCommand('ACL', 'DELUSER', $user);
            }
            $this->redis->close();
        }
    }
}
