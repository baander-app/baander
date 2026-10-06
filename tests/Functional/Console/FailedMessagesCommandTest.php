<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Shared\Application\Port\FailedMessageAdministrationInterface;
use App\Tests\Fixtures\Messaging\FailureTransportProbe;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Symfony's messenger:failed:* commands are the CLI counterparts of the admin
 * endpoints. They run in-process here against the PostgreSQL failure transport.
 */
final class FailedMessagesCommandTest extends KernelTestCase
{
    /** The Swoole pool resetter prints this when a retry worker releases its manager. */
    private const string RESET_NOTICE = "[swoole] Resetting Doctrine EntityManager: Doctrine\\ORM\\EntityManager\n";

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

    public function testShowListsFailedMessagesAndShowsOneById(): void
    {
        $first = $this->sendFailed('listed');
        $second = $this->sendFailed('detailed');

        $list = $this->runCommand('messenger:failed:show');
        self::assertSame(Command::SUCCESS, $list->getStatusCode(), $list->getDisplay());
        self::assertStringContainsString($first, $list->getDisplay());
        self::assertStringContainsString($second, $list->getDisplay());
        self::assertStringContainsString('Probe "listed" failed.', $list->getDisplay());

        $one = $this->runCommand('messenger:failed:show', ['id' => $second]);
        self::assertSame(Command::SUCCESS, $one->getStatusCode(), $one->getDisplay());
        self::assertStringContainsString(FailureTransportProbe::class, $one->getDisplay());
        self::assertStringContainsString('Probe "detailed" failed.', $one->getDisplay());
    }

    public function testRemoveByIdDeletesOnlyThatMessage(): void
    {
        $removed = $this->sendFailed('removed');
        $kept = $this->sendFailed('kept');

        $tester = $this->runCommand('messenger:failed:remove', ['id' => [$removed], '--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([$kept], $this->storedIds());
    }

    public function testRemoveAllSkipsMessagesWaitingOutARetryDelay(): void
    {
        $this->sendFailed('available');
        $delayed = $this->sendFailed('delayed', delayed: true);

        $tester = $this->runCommand('messenger:failed:remove', ['--all' => true, '--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([$delayed], $this->storedIds());
    }

    public function testRetryByIdHandlesTheMessageAndRemovesIt(): void
    {
        $this->expectOutputString(self::RESET_NOTICE);
        $retried = $this->sendFailed('recovered', fail: false);
        $other = $this->sendFailed('untouched');

        $tester = $this->runCommand('messenger:failed:retry', ['id' => [$retried], '--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([$other], $this->storedIds());
    }

    public function testRetryThatFailsAgainKeepsTheMessageWithAHigherRetryCount(): void
    {
        $this->expectOutputString(self::RESET_NOTICE);
        $id = $this->sendFailed('still broken');

        $tester = $this->runCommand('messenger:failed:retry', ['id' => [$id], '--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $ids = $this->storedIds();
        self::assertCount(1, $ids);
        self::assertNotSame($id, $ids[0]);
        $message = static::getContainer()->get(FailedMessageAdministrationInterface::class)->find($ids[0]);
        self::assertNotNull($message);
        self::assertSame(1, $message->retryCount);
        self::assertSame('Probe "still broken" failed.', $message->errorMessage);
    }

    /**
     * Symfony's documented semantics: a retry from the failure transport that fails
     * again is re-sent under the failure transport's retry strategy (three retries by
     * default), and the worker then discards it.
     */
    public function testTheFourthFailedRetryDiscardsTheMessage(): void
    {
        $this->expectOutputString(str_repeat(self::RESET_NOTICE, 4));
        $this->sendFailed('exhausted');

        $retryCounts = [];
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            [$id] = $this->storedIds();
            $tester = $this->runCommand('messenger:failed:retry', ['id' => [$id], '--force' => true]);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
            $remaining = $this->storedIds();
            $retryCounts[] = $remaining === [] ? null : static::getContainer()->get(FailedMessageAdministrationInterface::class)->find($remaining[0])?->retryCount;
        }

        self::assertSame([1, 2, 3, null], $retryCounts);
    }

    /** @param array<string, mixed> $input */
    private function runCommand(string $command, array $input = []): CommandTester
    {
        $tester = new CommandTester($this->application->find($command));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    private function sendFailed(string $label, bool $delayed = false, bool $fail = true): string
    {
        $transport = static::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(TransportInterface::class, $transport);
        $envelope = new Envelope(new FailureTransportProbe($label, $fail), [
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
