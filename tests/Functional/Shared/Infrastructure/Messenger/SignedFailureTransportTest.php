<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\Messenger;

use App\Shared\Application\Port\FailedMessageAdministrationInterface;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Tests\Fixtures\Messaging\FailureTransportProbe;
use App\Tests\Fixtures\Messaging\FailureTransportProbeCodec;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineTransportConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\SigningSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * The failure transport stores envelopes signed with APP_SECRET. A row that does not verify,
 * written by someone without the secret or signed under an earlier one, is never handled.
 */
final class SignedFailureTransportTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testTheTransportsSignWithTheApplicationSerializer(): void
    {
        self::bootKernel();

        self::assertInstanceOf(SigningSerializer::class, static::getContainer()->get('messenger.default_serializer'));
    }

    public function testAnUnsignedFailedMessageIsRemovedWhenAnAdministratorReadsIt(): void
    {
        self::bootKernel();
        $signed = $this->failureTransport()->send($this->failedProbe('signed'))->last(TransportMessageIdStamp::class)?->getId();
        $unsigned = $this->sendUnsigned('unsigned');
        $administration = static::getContainer()->get(FailedMessageAdministrationInterface::class);
        self::assertInstanceOf(FailedMessageAdministrationInterface::class, $administration);

        self::assertNull($administration->find($unsigned));
        self::assertNotNull($administration->find((string) $signed));
        self::assertSame([(string) $signed], $this->storedIds());
    }

    public function testRetryingAnUnsignedFailedMessageRunsNoHandlerAndDoesNotRelistIt(): void
    {
        $application = new Application(self::bootKernel());
        // The probe fails when handled, so a run would relist it under a new id.
        $id = $this->sendUnsigned('forged retry');

        $tester = new CommandTester($application->find('messenger:failed:retry'));
        $tester->execute(['id' => [$id], '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([], $this->storedIds());
    }

    private function failedProbe(string $label): Envelope
    {
        return new Envelope(new FailureTransportProbe($label, true), [
            new SentToFailureTransportStamp('async'),
            new RedeliveryStamp(0),
            new ErrorDetailsStamp(\RuntimeException::class, 0, sprintf('Probe "%s" failed.', $label)),
        ]);
    }

    /** Writes a failed row as a process without APP_SECRET could: the JSON contract, unsigned. */
    private function sendUnsigned(string $label): string
    {
        $transport = new DoctrineTransport(
            new DoctrineTransportConnection(['table_name' => 'failed_messages', 'queue_name' => 'failed', 'auto_setup' => false], $this->connection()),
            new JsonTransportSerializer(new JsonMessageCodec([new FailureTransportProbeCodec()])),
        );
        $id = $transport->send($this->failedProbe($label))->last(TransportMessageIdStamp::class)?->getId();
        self::assertNotNull($id);

        return (string) $id;
    }

    private function failureTransport(): TransportInterface
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

    /** @return list<string> */
    private function storedIds(): array
    {
        return array_map(strval(...), $this->connection()->fetchFirstColumn("SELECT id FROM failed_messages WHERE queue_name = 'failed' ORDER BY id"));
    }
}
