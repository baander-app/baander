<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Tests\Fixtures\Messaging\FailureTransportProbe;
use App\Tests\Functional\TestCase;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineTransportConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;

/** Runs the failure transport endpoints against the migrated PostgreSQL table. */
final class TransportControllerTest extends TestCase
{
    private const string BASE = '/api/monitor/transport';

    public function testFailureTransportIsTheListableDoctrineTable(): void
    {
        self::assertInstanceOf(DoctrineTransport::class, $this->failureTransport());
    }

    public function testListPagesFailedMessagesNewestFirstWithTheirDiagnostics(): void
    {
        $oldest = $this->sendFailed('first');
        $middle = $this->sendFailed('second');
        $newest = $this->sendFailed('third');
        $admin = $this->createAdminUser();

        $first = $this->assertJsonResponse($this->authenticatedRequest('GET', self::BASE . '/failed?limit=2', $admin), 200, 'data');
        self::assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 2, 'total' => 3], $first['meta']);
        self::assertSame([$newest, $middle], array_column($first['data'], 'id'));
        self::assertSame(FailureTransportProbe::class, $first['data'][0]['messageClass']);
        self::assertSame('async', $first['data'][0]['originalTransport']);
        self::assertSame(\RuntimeException::class, $first['data'][0]['errorClass']);
        self::assertSame('Probe "third" failed.', $first['data'][0]['errorMessage']);
        self::assertSame(0, $first['data'][0]['retryCount']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $first['data'][0]['failedAt']));

        $second = $this->assertJsonResponse($this->authenticatedRequest('GET', self::BASE . '/failed?page=2&limit=2', $admin), 200, 'data');
        self::assertSame([$oldest], array_column($second['data'], 'id'));
    }

    public function testShowReturnsOneFailedMessage(): void
    {
        $id = $this->sendFailed('shown', retryCount: 2);
        $admin = $this->createAdminUser();

        $data = $this->assertJsonResponse($this->authenticatedRequest('GET', self::BASE . '/failed/' . $id, $admin), 200, 'data');
        self::assertSame($id, $data['data']['id']);
        self::assertSame('Probe "shown" failed.', $data['data']['errorMessage']);
        self::assertSame(2, $data['data']['retryCount']);

        $this->assertJsonResponse($this->authenticatedRequest('GET', self::BASE . '/failed/' . ((int) $id + 1000), $admin), 404);
    }

    public function testRemoveDeletesOnlyTheGivenMessage(): void
    {
        $removed = $this->sendFailed('removed');
        $kept = $this->sendFailed('kept');
        $admin = $this->createAdminUser();

        $data = $this->assertJsonResponse($this->authenticatedRequest('DELETE', self::BASE . '/failed/' . $removed, $admin), 200, 'data');
        self::assertSame(['removed' => $removed], $data['data']);
        self::assertSame([$kept], $this->storedIds());

        $this->assertJsonResponse($this->authenticatedRequest('DELETE', self::BASE . '/failed/' . $removed, $admin), 404);
    }

    public function testFlushRemovesEveryFailedMessageIncludingUndecodableRows(): void
    {
        $this->sendFailed('one');
        $this->sendFailed('two', delayed: true);
        $this->connection()->executeStatement(
            "INSERT INTO failed_messages (body, headers, queue_name, created_at, available_at) VALUES ('not a message', '{}', 'failed', now(), now())",
        );

        $response = $this->authenticatedRequest('POST', self::BASE . '/failed/flush?confirm=true', $this->createAdminUser());

        $data = $this->assertJsonResponse($response, 200, 'data');
        self::assertSame(3, $data['data']['flushed']);
        self::assertSame([], $this->storedIds());
    }

    public function testFlushRequiresConfirmation(): void
    {
        $id = $this->sendFailed('unconfirmed');

        $response = $this->authenticatedRequest('POST', self::BASE . '/failed/flush', $this->createAdminUser());

        $this->assertJsonResponse($response, 422);
        self::assertSame([$id], $this->storedIds());
    }

    public function testStatusCountsMessagesInTheFailureTransport(): void
    {
        $admin = $this->createAdminUser();
        $before = $this->assertJsonResponse($this->authenticatedRequest('GET', self::BASE . '/status', $admin), 200, 'data');
        self::assertSame(0, $before['data']['failedQueueDepth']);

        $this->sendFailed('counted');
        $this->sendFailed('also counted');

        $after = $this->assertJsonResponse($this->authenticatedRequest('GET', self::BASE . '/status', $admin), 200, 'data');
        self::assertSame(2, $after['data']['failedQueueDepth']);
    }

    public function testAMessageThatExhaustsItsRetriesInAWorkerIsCountedAndListed(): void
    {
        // The Swoole pool resetter reports releasing the worker's manager.
        $this->expectOutputString("[swoole] Resetting Doctrine EntityManager: Doctrine\\ORM\\EntityManager\n");
        $async = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(TransportInterface::class, $async);
        // The async retry strategy allows three retries; this is the last attempt.
        $async->send(new Envelope(new FailureTransportProbe('exhausted', true), [new RedeliveryStamp(3)]));

        $consume = new CommandTester((new Application(static::$kernel))->find('messenger:consume'));
        $consume->execute(['receivers' => ['async'], '--limit' => 1, '--time-limit' => 10], ['interactive' => false]);
        self::assertSame(0, $consume->getStatusCode(), $consume->getDisplay());

        $admin = $this->createAdminUser();
        $status = $this->assertJsonResponse($this->authenticatedRequest('GET', self::BASE . '/status', $admin), 200, 'data');
        self::assertSame(1, $status['data']['failedQueueDepth']);
        $list = $this->assertJsonResponse($this->authenticatedRequest('GET', self::BASE . '/failed', $admin), 200, 'data');
        self::assertSame('async', $list['data'][0]['originalTransport']);
        self::assertSame('Probe "exhausted" failed.', $list['data'][0]['errorMessage']);
        self::assertSame(0, $list['data'][0]['retryCount']);
    }

    public function testFailedEndpointsRequireAnAdministrator(): void
    {
        $id = $this->sendFailed('protected');
        $user = $this->createTestUser();

        foreach ([['GET', '/failed'], ['GET', '/failed/' . $id], ['DELETE', '/failed/' . $id], ['POST', '/failed/' . $id . '/retry']] as [$method, $path]) {
            self::assertSame(403, $this->authenticatedRequest($method, self::BASE . $path, $user)->getStatusCode(), $method . ' ' . $path);
        }
        self::assertSame([$id], $this->storedIds());
    }

    /**
     * Retry runs messenger:failed:retry in a child process with its own database
     * connection, so these rows are committed through an independent connection.
     */
    public function testRetryHandlesTheMessageInTheConsoleCommand(): void
    {
        $observer = $this->observer();
        $label = 'retry-success-' . bin2hex(random_bytes(4));
        $id = $this->sendCommittedFailed($observer, $label, fail: false);
        try {
            $data = $this->assertJsonResponse($this->authenticatedRequest('POST', self::BASE . '/failed/' . $id . '/retry', $this->createAdminUser()), 200, 'data');

            self::assertSame(['retried' => $id], $data['data']);
            self::assertSame([], $this->committedIds($observer, $label));
        } finally {
            $this->deleteCommitted($observer, $label);
        }
    }

    public function testRetryThatFailsAgainReturnsTheMessageUnderANewId(): void
    {
        $observer = $this->observer();
        $label = 'retry-failure-' . bin2hex(random_bytes(4));
        $id = $this->sendCommittedFailed($observer, $label, fail: true);
        try {
            $admin = $this->createAdminUser();
            $this->assertJsonResponse($this->authenticatedRequest('POST', self::BASE . '/failed/' . $id . '/retry', $admin), 200, 'data');

            $ids = $this->committedIds($observer, $label);
            self::assertCount(1, $ids);
            self::assertNotSame($id, $ids[0]);
            $data = $this->assertJsonResponse($this->authenticatedRequest('GET', self::BASE . '/failed/' . $ids[0], $admin), 200, 'data');
            self::assertSame(1, $data['data']['retryCount']);
            self::assertSame(sprintf('Probe "%s" failed.', $label), $data['data']['errorMessage']);
        } finally {
            $this->deleteCommitted($observer, $label);
        }
    }

    public function testRetryOfAnUnknownMessageIsNotFound(): void
    {
        $response = $this->authenticatedRequest('POST', self::BASE . '/failed/987654321/retry', $this->createAdminUser());

        $this->assertJsonResponse($response, 404);
    }

    private function failureTransport(): TransportInterface
    {
        $transport = static::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(TransportInterface::class, $transport);

        return $transport;
    }

    private function connection(): DbalConnection
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(DbalConnection::class, $connection);

        return $connection;
    }

    /** Sends what SendFailedMessageToFailureTransportListener sends after the last attempt. */
    private function sendFailed(string $label, int $retryCount = 0, bool $delayed = false, bool $fail = true, ?TransportInterface $transport = null): string
    {
        $envelope = new Envelope(new FailureTransportProbe($label, $fail), [
            new SentToFailureTransportStamp('async'),
            new RedeliveryStamp($retryCount),
            new ErrorDetailsStamp(\RuntimeException::class, 0, sprintf('Probe "%s" failed.', $label)),
        ]);
        if ($delayed) {
            $envelope = $envelope->with(new DelayStamp(60_000));
        }
        $id = ($transport ?? $this->failureTransport())->send($envelope)->last(TransportMessageIdStamp::class)?->getId();
        self::assertNotNull($id);

        return (string) $id;
    }

    /** @return list<string> */
    private function storedIds(): array
    {
        return array_map(strval(...), $this->connection()->fetchFirstColumn("SELECT id FROM failed_messages WHERE queue_name = 'failed' ORDER BY id"));
    }

    private function observer(): DbalConnection
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to the fully migrated disposable PostgreSQL database selected by DATABASE_URL.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $params['serverVersion'] = '18';
        $observer = DriverManager::getConnection($params);
        self::assertSame($observer->fetchOne('SELECT current_database()'), $this->connection()->fetchOne('SELECT current_database()'));

        return $observer;
    }

    private function sendCommittedFailed(DbalConnection $observer, string $label, bool $fail): string
    {
        $serializer = static::getContainer()->get(JsonTransportSerializer::class);
        self::assertInstanceOf(JsonTransportSerializer::class, $serializer);
        $transport = new DoctrineTransport(
            new DoctrineTransportConnection(['table_name' => 'failed_messages', 'queue_name' => 'failed', 'auto_setup' => false], $observer),
            $serializer,
        );

        return $this->sendFailed($label, fail: $fail, transport: $transport);
    }

    /** @return list<string> */
    private function committedIds(DbalConnection $observer, string $label): array
    {
        return array_map(strval(...), $observer->fetchFirstColumn(
            'SELECT id FROM failed_messages WHERE position(? in body) > 0 ORDER BY id',
            [$label],
        ));
    }

    private function deleteCommitted(DbalConnection $observer, string $label): void
    {
        $observer->executeStatement('DELETE FROM failed_messages WHERE position(? in body) > 0', [$label]);
        $observer->close();
    }
}
