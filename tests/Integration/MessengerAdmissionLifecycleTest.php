<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Shared\Infrastructure\Messenger\OrdinaryAdmissionSubscriber;
use App\Shared\Infrastructure\Messenger\OrdinaryAdmissionTransport;
use App\Shared\Infrastructure\Worker\Autoscaling\AdmissionLedger;
use App\Shared\Infrastructure\Worker\Autoscaling\WorkerAdmissionContext;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\WorkerLaunchIdentity;
use App\Tests\Fixtures\Messaging\AdmissionObservedTransport;
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use App\Tests\Fixtures\Messaging\MessengerConfiguration;
use App\Tests\Fixtures\Messaging\ReplyLossProxy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Worker;

final class MessengerAdmissionLifecycleTest extends TestCase
{
    private string $directory;
    private string $boot;
    private string $stream;
    private AdmissionLedger $ledger;
    private Connection $connection;
    private \Redis $redis;

    protected function setUp(): void
    {
        $dsn = getenv('MESSENGER_TEST_REDIS_DSN');
        if (!$dsn) {
            self::markTestSkipped('Set MESSENGER_TEST_REDIS_DSN to disposable Redis.');
        }
        $this->directory = sys_get_temp_dir() . '/baander-admission-lifecycle-' . bin2hex(random_bytes(8));
        $this->boot = bin2hex(random_bytes(16));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/' . $this->boot, 0700);
        $this->ledger = new AdmissionLedger($this->directory, new DeploymentLease('worker.baander.app', $this->boot, 1),
            new WorkerAutoscalingProfile(maximumConcurrency: 2));
        $this->ledger->initialize(hrtime(true) / 1e9);
        $this->publishTarget(2);

        $this->stream = 'messenger_admission_' . bin2hex(random_bytes(12));
        $options = array_replace(MessengerConfiguration::messenger()['transports']['async']['options'], [
            'stream' => $this->stream, 'group' => 'test', 'consumer' => 'consumer-0', 'timeout' => 2.0, 'read_timeout' => 2.0,
        ]);
        $this->connection = Connection::fromDsn($dsn, $options);
        $parts = parse_url($dsn);
        self::assertIsArray($parts);
        $this->redis = new \Redis();
        $this->redis->connect($parts['host'], $parts['port'] ?? 6379, 2.0);
        if (isset($parts['pass'])) {
            $auth = isset($parts['user']) ? [rawurldecode($parts['user']), rawurldecode($parts['pass'])] : rawurldecode($parts['pass']);
            $this->redis->auth($auth);
        }
        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 2.0);
    }

    /** @return iterable<string, array{int}> */
    public static function reducedTargets(): iterable
    {
        yield 'all consumers parked' => [0];
        yield 'remaining permit held by another consumer' => [1];
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function lostReplies(): iterable
    {
        yield 'accepted receive' => ['XREADGROUP', 0, 'receiving'];
        yield 'accepted acknowledgment' => ['XACK', 1, 'completing'];
    }

    #[DataProvider('lostReplies')]
    public function testAcceptedRedisCommandWithLostReplyRetainsItsPermit(string $command, int $expectedEffects, string $stateAtCommand): void
    {
        $serializer = new JsonTransportSerializer(MessageCodecFactory::create());
        $original = new RedisTransport($this->connection, $serializer);
        $original->setup();
        $message = new ExtractAlbumCoverCommand(Uuid::v4());
        $id = $original->send(new Envelope($message))->last(TransportMessageIdStamp::class)?->getId();
        self::assertNotNull($id);
        $dsn = getenv('MESSENGER_TEST_REDIS_DSN');
        self::assertIsString($dsn);
        $parts = parse_url($dsn);
        self::assertIsArray($parts);
        $proxy = new ReplyLossProxy($this->directory, $parts['host'], $parts['port'] ?? 6379, $command,
            $this->directory . '/' . $this->boot . '/admission.json');
        $connection = null;
        try {
            $proxyDsn = preg_replace_callback('#(?<=://)[^/]+#', static function (array $match) use ($proxy): string {
                $at = strrpos($match[0], '@');
                return ($at === false ? '' : substr($match[0], 0, $at + 1)) . '127.0.0.1:' . $proxy->port();
            }, $dsn, 1);
            self::assertIsString($proxyDsn);
            $options = array_replace(MessengerConfiguration::messenger()['transports']['async']['options'], [
                'stream' => $this->stream, 'group' => 'test', 'consumer' => 'consumer-0',
                'timeout' => 2.0, 'read_timeout' => 2.0,
            ]);
            $connection = Connection::fromDsn($proxyDsn, $options);
            $transport = new OrdinaryAdmissionTransport(new RedisTransport($connection, $serializer), new WorkerAdmissionContext($this->ledger,
                new WorkerLaunchIdentity('worker.baander.app', $this->boot, 'consumer-0', 1)));
            $dispatcher = new EventDispatcher();
            $dispatcher->addSubscriber(new OrdinaryAdmissionSubscriber($transport));
            $effects = 0;
            $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
                ExtractAlbumCoverCommand::class => [static function (ExtractAlbumCoverCommand $received) use ($message, &$effects): void {
                    self::assertEquals($message, $received);
                    ++$effects;
                }],
            ]))]);
            $this->publishTarget(1);
            try {
                $this->runOneDelivery($transport, $bus, $dispatcher);
                self::fail('A lost accepted Redis reply must leave ownership uncertain.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('reconciliation', $error->getMessage());
            }
            $receipt = $proxy->receipt();
            self::assertSame($command, strtoupper($receipt['command'][0]));
            self::assertSame($stateAtCommand, $receipt['slot']['state']);
            $request = $receipt['slot']['requestId'];
            self::assertNotNull($request);
            self::assertSame($expectedEffects, $effects);
            $pending = $this->redis->xPending($this->stream, 'test', $id, $id, 1);
            if ($command === 'XREADGROUP') {
                self::assertSame($id, $receipt['response'][0][1][0][0]);
                self::assertCount(1, $pending);
                self::assertSame($id, $pending[0][0]);
                self::assertSame('consumer-0', $pending[0][1]);
            } else {
                self::assertSame([$this->stream, 'test', $id], array_slice($receipt['command'], 1));
                self::assertSame(1, $receipt['response']);
                self::assertSame([], $pending);
            }
            $snapshot = $this->snapshot();
            self::assertTrue($snapshot['fatal']);
            self::assertSame(0, $snapshot['target']);
            self::assertSame(0, $snapshot['completed']);
            self::assertSame('uncertain', $snapshot['slots']['consumer-0']['state']);
            self::assertSame($request, $snapshot['slots']['consumer-0']['requestId']);
            $this->publishTarget(1);
            try {
                iterator_to_array($transport->get());
                self::fail('An uncertain permit must deny another receive.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('reconciliation', $error->getMessage());
            }
            $afterDeniedReceive = $this->snapshot();
            self::assertSame($request, $afterDeniedReceive['slots']['consumer-0']['requestId']);
            self::assertSame('uncertain', $afterDeniedReceive['slots']['consumer-0']['state']);
            self::assertTrue($afterDeniedReceive['fatal']);
            self::assertSame(0, $afterDeniedReceive['target']);
            self::assertSame(0, $afterDeniedReceive['completed']);
            self::assertSame($expectedEffects, $effects);
            $after = $this->redis->xPending($this->stream, 'test', $id, $id, 1);
            self::assertCount(count($pending), $after);
            if ($command === 'XREADGROUP') {
                self::assertSame($id, $after[0][0]);
                self::assertSame('consumer-0', $after[0][1]);
            }
        } finally {
            $connection?->close();
            $proxy->close();
        }
    }

    #[DataProvider('reducedTargets')]
    public function testWorkerPublishesRetryAndCompletesOwnedDeliveryBeforeParking(int $target): void
    {
        $inner = new RedisTransport($this->connection, new JsonTransportSerializer(MessageCodecFactory::create()));
        $inner->setup();
        $message = new ExtractAlbumCoverCommand(Uuid::v4());
        $originalId = $inner->send(new Envelope($message))->last(TransportMessageIdStamp::class)?->getId();
        self::assertNotNull($originalId);
        $this->publishTarget(2);
        $peer = new WorkerLaunchIdentity('worker.baander.app', $this->boot, 'consumer-1', 1);
        $peerRequest = bin2hex(random_bytes(16));
        if ($target === 1) {
            self::assertTrue($this->ledger->acquire($peer, 1, $peerRequest, hrtime(true) / 1e9));
        }

        $observations = [];
        $observed = new AdmissionObservedTransport($inner, function (string $operation, Envelope $envelope) use (&$observations): void {
            $id = $envelope->last(TransportMessageIdStamp::class)?->getId();
            $observations[$operation][] = [
                'slot' => $this->snapshot()['slots']['consumer-0'],
                'exists' => $this->redis->xRange($this->stream, $id, $id) !== [],
                'pending' => $this->redis->xPending($this->stream, 'test')[0],
            ];
        });
        $transport = new OrdinaryAdmissionTransport($observed, new WorkerAdmissionContext($this->ledger,
            new WorkerLaunchIdentity('worker.baander.app', $this->boot, 'consumer-0', 1)));
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new OrdinaryAdmissionSubscriber($transport));
        $activeEnvelope = null;
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, static function (WorkerMessageReceivedEvent $event) use (&$activeEnvelope): void {
            $activeEnvelope = $event->getEnvelope();
        });
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            new ServiceLocator(['async' => static fn () => $transport]),
            new ServiceLocator(['async' => static fn () => new MultiplierRetryStrategy(delayMilliseconds: 0, jitter: 0)]),
            eventDispatcher: $dispatcher,
        ));
        $retried = [];
        $dispatcher->addListener(WorkerMessageRetriedEvent::class, static function (WorkerMessageRetriedEvent $event) use (&$retried): void {
            $retried[] = $event->getEnvelope();
        });
        $calls = 0;
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            ExtractAlbumCoverCommand::class => [function (ExtractAlbumCoverCommand $received) use (&$calls, &$activeEnvelope, $message, $target, $transport): void {
                self::assertEquals($message, $received);
                ++$calls;
                self::assertSame('busy', $this->snapshot()['slots']['consumer-0']['state']);
                $this->publishTarget($calls === 1 ? $target : 0);
                self::assertInstanceOf(Envelope::class, $activeEnvelope);
                $transport->keepalive($activeEnvelope, 30);
                if ($calls === 1) {
                    throw new RecoverableMessageHandlingException('Retry after temporary failure.', retryDelay: 0);
                }
            }],
        ]))]);

        $this->runOneDelivery($transport, $bus, $dispatcher);
        self::assertSame(1, $calls);
        self::assertCount(1, $retried);
        self::assertSame(1, $retried[0]->last(RedeliveryStamp::class)?->getRetryCount());
        self::assertSame('busy', $observations['sent'][0]['slot']['state']);
        self::assertNotNull($observations['sent'][0]['slot']['requestId']);
        self::assertTrue($observations['sent'][0]['exists']);
        self::assertSame(1, $observations['sent'][0]['pending']);
        self::assertSame('busy', $observations['keepalive'][0]['slot']['state']);
        self::assertSame(1, $observations['keepalive'][0]['pending']);
        $this->assertCompletionRetainsOwnership($observations, 'reject');
        self::assertSame(1, $this->snapshot()['completed']);
        self::assertNull($this->snapshot()['slots']['consumer-0']['requestId']);
        self::assertSame(1, $this->redis->xLen($this->stream));
        self::assertSame([], $this->redis->xRange($this->stream, $originalId, $originalId));
        $retryId = $retried[0]->last(TransportMessageIdStamp::class)?->getId();
        self::assertNotNull($retryId);
        self::assertNotSame($originalId, $retryId);
        self::assertNotEmpty($this->redis->xRange($this->stream, $retryId, $retryId));

        $this->publishTarget($target);
        self::assertSame([], iterator_to_array($transport->get()));
        self::assertSame('parked', $this->snapshot()['slots']['consumer-0']['state']);
        self::assertSame(0, $this->redis->xPending($this->stream, 'test')[0]);
        self::assertSame(1, $this->redis->xLen($this->stream));
        if ($target === 1) {
            $this->ledger->complete($peer, $peerRequest, hrtime(true) / 1e9);
        }
        $this->publishTarget(1);
        $this->runOneDelivery($transport, $bus, $dispatcher);
        self::assertSame(2, $calls);
        self::assertCount(2, $observations['keepalive']);
        $this->assertCompletionRetainsOwnership($observations, 'ack');
        self::assertSame(2, $this->snapshot()['completed']);
        self::assertNull($this->snapshot()['slots']['consumer-0']['requestId']);
        self::assertSame(0, $this->redis->xLen($this->stream));
        self::assertSame(0, $this->redis->xPending($this->stream, 'test')[0]);
    }

    public function testFailedRetryPublicationRetainsOriginalPendingDeliveryAndAdmission(): void
    {
        $serializer = new JsonTransportSerializer(MessageCodecFactory::create());
        $original = new RedisTransport($this->connection, $serializer);
        $original->setup();
        $message = new ExtractAlbumCoverCommand(Uuid::v4());
        $originalId = $original->send(new Envelope($message))->last(TransportMessageIdStamp::class)?->getId();
        self::assertNotNull($originalId);
        $user = 'admission_test_' . bin2hex(random_bytes(12));
        $password = bin2hex(random_bytes(16));
        $restricted = null;
        try {
            // Restrict only a disposable principal; the admin connection stays
            // available to inspect retained data and remove the fixture.
            self::assertNotFalse($this->redis->rawCommand('ACL', 'SETUSER', $user, 'on', '>' . $password,
                'resetkeys', '~' . $this->stream . '*', '+@all', '-xadd'));
            $options = array_replace(MessengerConfiguration::messenger()['transports']['async']['options'], [
                'stream' => $this->stream, 'group' => 'test', 'consumer' => 'consumer-0',
                'timeout' => 2.0, 'read_timeout' => 2.0, 'auth' => [$user, $password],
            ]);
            $dsn = getenv('MESSENGER_TEST_REDIS_DSN');
            self::assertIsString($dsn);
            $restricted = Connection::fromDsn($dsn, $options);
            $inner = new RedisTransport($restricted, $serializer);
            $inner->setup();
            $transport = new OrdinaryAdmissionTransport($inner, new WorkerAdmissionContext($this->ledger,
                new WorkerLaunchIdentity('worker.baander.app', $this->boot, 'consumer-0', 1)));
            $dispatcher = new EventDispatcher();
            $dispatcher->addSubscriber(new OrdinaryAdmissionSubscriber($transport));
            $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
                new ServiceLocator(['async' => static fn () => $transport]),
                new ServiceLocator(['async' => static fn () => new MultiplierRetryStrategy(delayMilliseconds: 0, jitter: 0)]),
                eventDispatcher: $dispatcher,
            ));
            $calls = 0;
            $retried = 0;
            $dispatcher->addListener(WorkerMessageRetriedEvent::class, static function () use (&$retried): void { ++$retried; });
            $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
                ExtractAlbumCoverCommand::class => [function (ExtractAlbumCoverCommand $received) use ($message, &$calls): void {
                    self::assertEquals($message, $received);
                    ++$calls;
                    $this->publishTarget(0);
                    throw new RecoverableMessageHandlingException('Retry after temporary failure.', retryDelay: 0);
                }],
            ]))]);
            $this->publishTarget(1);
            try {
                $this->runOneDelivery($transport, $bus, $dispatcher);
                self::fail('The worker must fail when Redis denies retry publication.');
            } catch (TransportException $error) {
                self::assertStringContainsString('NOPERM', $error->getMessage());
            }

            self::assertSame(1, $calls);
            self::assertSame(0, $retried);
            $snapshot = $this->snapshot();
            self::assertSame(0, $snapshot['target']);
            self::assertSame(0, $snapshot['completed']);
            self::assertSame('busy', $snapshot['slots']['consumer-0']['state']);
            $request = $snapshot['slots']['consumer-0']['requestId'];
            self::assertNotNull($request);
            self::assertSame(1, $this->redis->xLen($this->stream));
            self::assertNotEmpty($this->redis->xRange($this->stream, $originalId, $originalId));
            self::assertSame(1, $this->redis->xPending($this->stream, 'test')[0]);
            self::assertSame($originalId, $this->redis->xPending($this->stream, 'test', '-', '+', 1)[0][0]);

            // Even reopening the target cannot admit another receive while the
            // failed worker still owns its original pending delivery.
            $this->publishTarget(1);
            try {
                iterator_to_array($transport->get());
                self::fail('An unfinished retry lifecycle must require reconciliation.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('reconciliation', $error->getMessage());
            }
            $snapshot = $this->snapshot();
            self::assertTrue($snapshot['fatal']);
            self::assertSame(0, $snapshot['target']);
            self::assertSame('uncertain', $snapshot['slots']['consumer-0']['state']);
            self::assertSame($request, $snapshot['slots']['consumer-0']['requestId']);
            self::assertSame(1, $this->redis->xPending($this->stream, 'test')[0]);
            self::assertNotEmpty($this->redis->xRange($this->stream, $originalId, $originalId));
        } finally {
            $restricted?->close();
            $this->redis->del($this->stream, $this->stream . '__queue');
            $this->redis->rawCommand('ACL', 'DELUSER', $user);
        }
    }

    /** @param array<string, list<array<string, mixed>>> $observations */
    private function assertCompletionRetainsOwnership(array $observations, string $operation): void
    {
        $before = $observations['before_' . $operation][0];
        $after = $observations['after_' . $operation][0];
        self::assertSame('completing', $before['slot']['state']);
        self::assertNotNull($before['slot']['requestId']);
        self::assertSame($before['slot']['requestId'], $after['slot']['requestId']);
        self::assertSame('completing', $after['slot']['state']);
        self::assertTrue($before['exists']);
        self::assertFalse($after['exists']);
        self::assertSame(1, $before['pending']);
        self::assertSame(0, $after['pending']);
    }

    private function runOneDelivery(OrdinaryAdmissionTransport $transport, MessageBus $bus, EventDispatcher $dispatcher): void
    {
        $polls = 0;
        $stop = static function (WorkerRunningEvent $event) use (&$polls): void {
            if (!$event->isWorkerIdle() || ++$polls >= 5) {
                $event->getWorker()->stop();
            }
        };
        $dispatcher->addListener(WorkerRunningEvent::class, $stop);
        try {
            (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);
        } finally {
            $dispatcher->removeListener(WorkerRunningEvent::class, $stop);
        }
    }

    private function publishTarget(int $target): void
    {
        $now = hrtime(true) / 1e9;
        foreach (['control', 'execution'] as $unit) {
            $path = $this->directory . '/' . $this->boot . '/' . $unit . '.json';
            file_put_contents($path, json_encode(['namespace' => 'worker.baander.app', 'bootId' => $this->boot, 'unit' => $unit,
                'epoch' => 1, 'ready' => true, 'draining' => false, 'time' => $now], JSON_THROW_ON_ERROR));
            chmod($path, 0600);
        }
        $this->ledger->publishDecision($target, 'test-demand', [], $now);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array { return $this->ledger->snapshot(hrtime(true) / 1e9); }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->cleanup();
            $this->connection->close();
        }
        if (isset($this->redis)) {
            $this->redis->close();
        }
        if (isset($this->directory)) {
            foreach (glob($this->directory . '/' . $this->boot . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory . '/' . $this->boot);
            rmdir($this->directory);
        }
    }
}
