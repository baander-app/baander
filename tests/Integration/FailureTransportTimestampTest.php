<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Fixtures\Messaging\FailureTransportProbe;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * symfony/doctrine-messenger binds its instants with Doctrine's `datetime_immutable` type, which
 * the project instant type replaces: failed_messages stores the send instant with its
 * microseconds in any session zone, and the receiver's own comparisons still find the message.
 */
final class FailureTransportTimestampTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    /** @return iterable<string, array{?string}> */
    public static function sessionZones(): iterable
    {
        yield 'UTC session' => [null];
        // Transaction-scoped: the test transaction rolls it back with the row.
        yield 'Kiritimati session' => ['Pacific/Kiritimati'];
    }

    #[DataProvider('sessionZones')]
    public function testTheStoredInstantsAreTheSendInstantAndTheReceiverFindsTheMessage(?string $zone): void
    {
        self::bootKernel();
        if ($zone !== null) {
            $this->connection()->executeStatement("SET LOCAL TIME ZONE '" . $zone . "'");
        }
        $before = $this->now();

        $id = $this->send($zone ?? 'utc');

        $after = $this->now();
        $stored = $this->connection()->fetchAssociative(
            "SELECT to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS created_at,
                    to_char(available_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS available_at
             FROM failed_messages WHERE id = ?",
            [$id],
        );
        self::assertIsArray($stored);
        // Microsecond text compares in time order; a whole-second value falls outside the window.
        foreach ($stored as $column => $instant) {
            self::assertGreaterThanOrEqual($before, $instant, $column);
            self::assertLessThanOrEqual($after, $instant, $column);
        }

        $receiver = $this->transport();
        self::assertInstanceOf(ListableReceiverInterface::class, $receiver);
        self::assertNotNull($receiver->find($id));
        $listed = array_map(
            static fn (Envelope $envelope): string => (string) $envelope->last(TransportMessageIdStamp::class)?->getId(),
            iterator_to_array($receiver->all(), false),
        );
        self::assertContains($id, $listed);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function send(string $label): string
    {
        $id = $this->transport()->send(new Envelope(new FailureTransportProbe($label, true)))->last(TransportMessageIdStamp::class)?->getId();
        self::assertNotNull($id);

        return (string) $id;
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
