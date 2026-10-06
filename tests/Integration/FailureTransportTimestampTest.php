<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Fixtures\Messaging\FailureTransportProbe;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * symfony/doctrine-messenger binds instants as UTC wall-clock text without an
 * offset. The failed_messages timestamptz columns store the right instant only in
 * a UTC session, which is the database default; a session in another zone shifts
 * the stored instant but keeps the transport's own comparisons consistent.
 */
final class FailureTransportTimestampTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testAUtcSessionStoresTheSendInstant(): void
    {
        self::bootKernel();
        self::assertSame('UTC', $this->connection()->fetchOne("SELECT to_char(now(), 'TZ')"));
        $before = time();

        $id = $this->send('utc');

        self::assertEqualsWithDelta($before, $this->createdAtEpoch($id), 2);
    }

    public function testAnotherSessionZoneShiftsTheInstantButTheReceiverStillFindsTheMessage(): void
    {
        self::bootKernel();
        // Transaction-scoped: the test transaction rolls it back with the row.
        $this->connection()->executeStatement("SET LOCAL TIME ZONE 'Pacific/Kiritimati'");
        $before = time();

        $id = $this->send('shifted');

        self::assertEqualsWithDelta($before - 14 * 3600, $this->createdAtEpoch($id), 2);
        $receiver = $this->transport();
        self::assertInstanceOf(ListableReceiverInterface::class, $receiver);
        self::assertNotNull($receiver->find($id));
        $listed = array_map(
            static fn (Envelope $envelope): string => (string) $envelope->last(TransportMessageIdStamp::class)?->getId(),
            iterator_to_array($receiver->all(), false),
        );
        self::assertContains($id, $listed);
    }

    private function send(string $label): string
    {
        $id = $this->transport()->send(new Envelope(new FailureTransportProbe($label, true)))->last(TransportMessageIdStamp::class)?->getId();
        self::assertNotNull($id);

        return (string) $id;
    }

    private function createdAtEpoch(string $id): int
    {
        return (int) $this->connection()->fetchOne('SELECT extract(epoch FROM created_at)::bigint FROM failed_messages WHERE id = ?', [$id]);
    }

    private function transport(): TransportInterface
    {
        $transport = static::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(TransportInterface::class, $transport);

        return $transport;
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
