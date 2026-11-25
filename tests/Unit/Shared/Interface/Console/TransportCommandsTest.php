<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Console;

use App\Shared\Application\DTO\FailedMessage;
use App\Shared\Application\DTO\FailedMessagePage;
use App\Shared\Application\FailureTransportUnavailableException;
use App\Shared\Application\Port\AsyncTransportUnavailableException;
use App\Shared\Application\Port\FailedMessageAdministrationInterface;
use App\Shared\Application\Port\TransportStatus;
use App\Shared\Application\Port\TransportStatusInterface;
use App\Shared\Interface\Console\FailedMessageFlushCommand;
use App\Shared\Interface\Console\FailedMessageListCommand;
use App\Shared\Interface\Console\MonitorTransportCommand;
use App\Shared\Interface\Controller\TransportController;
use App\Shared\Interface\Exception\InvalidQueryParameter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

final class TransportCommandsTest extends TestCase
{
    private FakeFailureTransport $failedMessages;

    private FakeTransportStatus $status;

    protected function setUp(): void
    {
        $this->failedMessages = new FakeFailureTransport();
        $this->status = new FakeTransportStatus();
    }

    public function testTransportShowsTheQueueDepthsAndConsumerTheDashboardShows(): void
    {
        $this->status->status = new TransportStatus(asyncQueueDepth: 7, failedQueueDepth: 3, consumerName: 'worker-baander-app', consumerRunning: true);
        $tester = new CommandTester(new MonitorTransportCommand($this->status));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/Async queue\s+7/', $display);
        self::assertMatchesRegularExpression('/Failed queue\s+3/', $display);
        self::assertMatchesRegularExpression('/Consumer\s+worker-baander-app/', $display);
        self::assertMatchesRegularExpression('/Consumer running\s+yes/', $display);
    }

    public function testTransportJsonIsTheDataTheStatusEndpointReturns(): void
    {
        $this->status->status = new TransportStatus(asyncQueueDepth: 0, failedQueueDepth: 2, consumerName: 'worker-baander-app', consumerRunning: false);
        $tester = new CommandTester(new MonitorTransportCommand($this->status));

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));

        $endpoint = json_decode((string) $this->controller()->status()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            ['asyncQueueDepth' => 0, 'failedQueueDepth' => 2, 'consumerName' => 'worker-baander-app', 'consumerRunning' => false],
            $endpoint['data'],
        );
        self::assertSame($endpoint['data'], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testTransportFailsWhenRedisIsUnavailable(): void
    {
        $this->status->failure = new AsyncTransportUnavailableException('Redis unavailable: connection refused');
        $tester = new CommandTester(new MonitorTransportCommand($this->status));

        self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('Redis unavailable: connection refused', $tester->getErrorOutput());
        self::assertSame(503, $this->controller()->status()->getStatusCode());
    }

    public function testListJsonIsTheDataOfTheApiPage(): void
    {
        $this->failedMessages->messages = [self::message('3', 'third'), self::message('2', 'second'), self::message('1', 'first')];
        $tester = new CommandTester(new FailedMessageListCommand($this->failedMessages));

        self::assertSame(Command::SUCCESS, $tester->execute(['--page' => '2', '--limit' => '2', '--json' => true]));

        $endpoint = json_decode((string) $this->controller()->listFailed(new Request(['page' => '2', 'limit' => '2']))->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['1'], array_column($endpoint['data'], 'id'));
        self::assertSame($endpoint['data'], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testListTableShowsOneRowPerMessageAndThePage(): void
    {
        $this->failedMessages->messages = [self::message('3', 'third'), self::message('2', 'second'), self::message('1', 'first')];
        $tester = new CommandTester(new FailedMessageListCommand($this->failedMessages));

        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '2']));

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/^\s*3\s+App\\\\Probe\\\\Third\s+async\s+RuntimeException: Probe "third" failed\.\s+2026-10-08T12:00:00\.000\+00:00\s+1\s*$/m', $display);
        self::assertStringContainsString('Probe "second" failed.', $display);
        self::assertStringNotContainsString('Probe "first" failed.', $display);
        self::assertStringContainsString('Page 1 of 2, 3 messages in total.', $display);
    }

    public function testListReadsThePageTheApiReads(): void
    {
        $tester = new CommandTester(new FailedMessageListCommand($this->failedMessages));

        self::assertSame(Command::SUCCESS, $tester->execute(['--page' => '3', '--limit' => '20']));
        self::assertSame([20, 40], $this->failedMessages->lastSlice);
    }

    /** @return iterable<string, array{array<string, string>, string, string}> */
    public static function refusedPages(): iterable
    {
        yield 'offset overflow' => [['--page' => (string) PHP_INT_MAX, '--limit' => '100'], 'page=9223372036854775807&limit=100', '--page must be between 1 and 92233720368547758.'];
        yield 'page 0' => [['--page' => '0'], 'page=0', '--page must be between 1 and 184467440737095516.'];
        yield 'non-numeric page' => [['--page' => 'abc'], 'page=abc', '--page must be an integer.'];
        yield 'excess limit' => [['--limit' => '500'], 'limit=500', '--limit must be between 1 and 100.'];
        yield 'zero limit' => [['--limit' => '0'], 'limit=0', '--limit must be between 1 and 100.'];
    }

    /** @param array<string, string> $options */
    #[DataProvider('refusedPages')]
    public function testListRefusesThePagesTheApiRefuses(array $options, string $query, string $message): void
    {
        $tester = new CommandTester(new FailedMessageListCommand($this->failedMessages));

        self::assertSame(Command::INVALID, $tester->execute($options, ['capture_stderr_separately' => true]));
        self::assertStringContainsString($message, $tester->getErrorOutput());
        self::assertNull($this->failedMessages->lastSlice);

        parse_str($query, $parameters);
        $this->expectException(InvalidQueryParameter::class);
        $this->controller()->listFailed(new Request($parameters));
    }

    public function testListOfAnEmptyTransportSaysSo(): void
    {
        $tester = new CommandTester(new FailedMessageListCommand($this->failedMessages));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('The failure transport holds no messages.', $tester->getDisplay());
    }

    public function testListFailsWhenTheFailureTransportIsUnavailable(): void
    {
        $this->failedMessages->failure = new FailureTransportUnavailableException('connection refused');
        $tester = new CommandTester(new FailedMessageListCommand($this->failedMessages));

        self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('Failure transport unavailable: connection refused', $tester->getErrorOutput());
    }

    public function testFlushWithoutATerminalAndWithoutForceRemovesNothing(): void
    {
        $this->failedMessages->messages = [self::message('1', 'kept')];
        $tester = new CommandTester(new FailedMessageFlushCommand($this->failedMessages));

        self::assertSame(Command::INVALID, $tester->execute([], ['interactive' => false, 'capture_stderr_separately' => true]));
        self::assertSame(0, $this->failedMessages->removeAllCalls);
        self::assertStringContainsString('--force', $tester->getErrorOutput());
    }

    public function testFlushWithForceRemovesEveryMessage(): void
    {
        $this->failedMessages->messages = [self::message('2', 'two'), self::message('1', 'one')];
        $tester = new CommandTester(new FailedMessageFlushCommand($this->failedMessages));

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true], ['interactive' => false]));
        self::assertSame(1, $this->failedMessages->removeAllCalls);
        self::assertStringContainsString('Removed 2 failed messages.', $tester->getDisplay());
    }

    public function testFlushJsonIsTheDataTheEndpointReturns(): void
    {
        $this->failedMessages->messages = [self::message('2', 'two'), self::message('1', 'one')];
        $tester = new CommandTester(new FailedMessageFlushCommand($this->failedMessages));

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true, '--json' => true], ['interactive' => false]));
        self::assertSame(['flushed' => 2], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));

        $this->failedMessages->messages = [self::message('2', 'two'), self::message('1', 'one')];
        $endpoint = json_decode((string) $this->controller()->flushFailed(new Request(['confirm' => 'true']))->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($endpoint['data'], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testFlushRemovesNothingWhenTheOperatorDeclines(): void
    {
        $this->failedMessages->messages = [self::message('1', 'kept')];
        $tester = new CommandTester(new FailedMessageFlushCommand($this->failedMessages));
        $tester->setInputs(['no']);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame(0, $this->failedMessages->removeAllCalls);
    }

    public function testFlushFailsWhenTheFailureTransportIsUnavailable(): void
    {
        $this->failedMessages->failure = new FailureTransportUnavailableException('connection refused');
        $tester = new CommandTester(new FailedMessageFlushCommand($this->failedMessages));

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('Failure transport unavailable: connection refused', $tester->getErrorOutput());
    }

    private function controller(): TransportController
    {
        return new TransportController($this->status, $this->failedMessages);
    }

    private static function message(string $id, string $label): FailedMessage
    {
        return new FailedMessage(
            id: $id,
            messageClass: 'App\\Probe\\' . ucfirst($label),
            originalTransport: 'async',
            errorClass: \RuntimeException::class,
            errorMessage: sprintf('Probe "%s" failed.', $label),
            failedAt: new \DateTimeImmutable('2026-10-08T12:00:00+00:00'),
            retryCount: 1,
        );
    }
}

final class FakeTransportStatus implements TransportStatusInterface
{
    public TransportStatus $status;

    public ?\RuntimeException $failure = null;

    public function __construct()
    {
        $this->status = new TransportStatus(0, 0, 'worker-baander-app', false);
    }

    public function status(): TransportStatus
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->status;
    }
}

final class FakeFailureTransport implements FailedMessageAdministrationInterface
{
    /** @var list<FailedMessage> newest first */
    public array $messages = [];

    public ?\RuntimeException $failure = null;

    /** @var array{int, int}|null the limit and offset of the last read */
    public ?array $lastSlice = null;

    public int $removeAllCalls = 0;

    public function count(): int
    {
        $this->failIfUnavailable();

        return count($this->messages);
    }

    public function newest(int $limit, int $offset): FailedMessagePage
    {
        $this->failIfUnavailable();
        $this->lastSlice = [$limit, $offset];

        return new FailedMessagePage(array_slice($this->messages, $offset, $limit), count($this->messages));
    }

    public function find(string $id): ?FailedMessage
    {
        throw new \LogicException('Not used by these commands.');
    }

    public function retry(string $id): bool
    {
        throw new \LogicException('Not used by these commands.');
    }

    public function remove(string $id): bool
    {
        throw new \LogicException('Not used by these commands.');
    }

    public function removeAll(): int
    {
        $this->failIfUnavailable();
        ++$this->removeAllCalls;
        $removed = count($this->messages);
        $this->messages = [];

        return $removed;
    }

    private function failIfUnavailable(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
