<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Shared\Interface\Controller\TransportController;
use App\Tests\Fixtures\Messaging\FailureTransportProbe;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * app:monitor:transport and app:failed-message:* read and change the PostgreSQL
 * failure transport through the same port as the admin endpoints, so they also
 * see messages waiting out a retry delay, which messenger:failed:show and
 * messenger:failed:remove --all skip.
 */
final class TransportCommandsTest extends KernelTestCase
{
    private Application $application;

    protected function setUp(): void
    {
        $this->application = new Application(self::bootKernel());
    }

    protected function tearDown(): void
    {
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testFlushWithForceRemovesMessagesWaitingOutARetryDelayAsTheWebFlushDoes(): void
    {
        $this->sendFailed('available');
        $this->sendFailed('delayed', delayed: true);

        $tester = $this->runCommand('app:failed-message:flush', ['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Removed 2 failed messages.', $tester->getDisplay());
        self::assertSame([], $this->storedIds());
    }

    public function testFlushWithoutATerminalAndWithoutForceRemovesNothing(): void
    {
        $available = $this->sendFailed('available');
        $delayed = $this->sendFailed('delayed', delayed: true);

        $tester = $this->runCommand('app:failed-message:flush');

        self::assertSame(Command::INVALID, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([$available, $delayed], $this->storedIds());
    }

    public function testListIncludesDelayedMessagesAndMatchesTheApiPage(): void
    {
        $oldest = $this->sendFailed('first');
        $delayed = $this->sendFailed('second', delayed: true);
        $newest = $this->sendFailed('third');

        $pages = [];
        foreach (['1', '2'] as $page) {
            $tester = $this->runCommand('app:failed-message:list', ['--page' => $page, '--limit' => '2', '--json' => true]);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

            $api = $this->controller()->listFailed(new Request(['page' => $page, 'limit' => '2']));
            self::assertSame(200, $api->getStatusCode());
            $expected = json_decode((string) $api->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
            self::assertSame($expected, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR), 'page ' . $page);
            $pages[] = array_column($expected, 'id');
        }

        self::assertSame([[$newest, $delayed], [$oldest]], $pages);

        $table = $this->runCommand('app:failed-message:list');
        self::assertStringContainsString('Probe "second" failed.', $table->getDisplay());
        self::assertStringContainsString('Page 1 of 1, 3 messages in total.', $table->getDisplay());
    }

    public function testTransportReportsWhatTheStatusEndpointReports(): void
    {
        $this->sendFailed('counted');
        $this->sendFailed('delayed', delayed: true);

        $tester = $this->runCommand('app:monitor:transport', ['--json' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $api = $this->controller()->status();
        self::assertSame(200, $api->getStatusCode(), (string) $api->getContent());
        $expected = json_decode((string) $api->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame($expected, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(2, $expected['failedQueueDepth']);
        self::assertSame(['asyncQueueDepth', 'failedQueueDepth', 'consumerName', 'consumerRunning'], array_keys($expected));
    }

    /** @param array<string, mixed> $input */
    private function runCommand(string $command, array $input = []): CommandTester
    {
        $tester = new CommandTester($this->application->find($command));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    private function controller(): TransportController
    {
        $controller = static::getContainer()->get(TransportController::class);
        self::assertInstanceOf(TransportController::class, $controller);

        return $controller;
    }

    private function sendFailed(string $label, bool $delayed = false): string
    {
        $transport = static::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(TransportInterface::class, $transport);
        $envelope = new Envelope(new FailureTransportProbe($label, true), [
            new SentToFailureTransportStamp('async'),
            new RedeliveryStamp(0),
            new ErrorDetailsStamp(\RuntimeException::class, 0, sprintf('Probe "%s" failed.', $label)),
        ]);
        if ($delayed) {
            $envelope = $envelope->with(new DelayStamp(60_000));
        }
        $id = $transport->send($envelope)->last(TransportMessageIdStamp::class)?->getId();
        self::assertNotNull($id);

        return (string) $id;
    }

    /** @return list<string> */
    private function storedIds(): array
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return array_map(strval(...), $connection->fetchFirstColumn("SELECT id FROM failed_messages WHERE queue_name = 'failed' ORDER BY id"));
    }
}
