<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Console;

use App\Shared\Application\Port\ServerControlPortInterface;
use App\Shared\Application\Port\ServerControlResult;
use App\Shared\Application\Port\ServerNotRunningException;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Swoole\ServerDiagnostics;
use App\Shared\Interface\Console\ServerCoroutinesCommand;
use App\Shared\Interface\Console\ServerSpansCommand;
use App\Shared\Interface\Console\ServerStatsCommand;
use App\Shared\Interface\Console\ServerWorkersCommand;
use App\Shared\Interface\Controller\CoroutineStatsController;
use App\Shared\Interface\Controller\ServerStatsControllerWithCoroutines;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ServerDiagnosticsCommandsTest extends TestCase
{
    /** @var array<string, ServerControlResult|\Throwable> operation => answer */
    private array $answers = [];

    /** @var list<array{string, array<string, mixed>}> */
    private array $calls = [];

    public function testStatsListsOneRowPerWorkerPlusTheSharedRedisAndSseFigures(): void
    {
        $this->answers['debug.stats'] = new ServerControlResult([
            0 => $this->workerStats(4101, 21.5),
            1 => $this->workerStats(4102, 22.25),
            2 => $this->workerStats(4103, 23.0),
        ]);
        $tester = new CommandTester(new ServerStatsCommand($this->diagnostics()));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        foreach (['4101', '4102', '4103', '21.5', '22.25', '23'] as $figure) {
            self::assertStringContainsString($figure, $display);
        }
        self::assertMatchesRegularExpression('/^\s*0\s+4101\b/m', $display);
        self::assertMatchesRegularExpression('/^\s*2\s+4103\b/m', $display);
        self::assertMatchesRegularExpression('/DB size\s+42/', $display);
        self::assertMatchesRegularExpression('/Connected clients\s+7/', $display);
        self::assertMatchesRegularExpression('/Ping\s+PONG/', $display);
        self::assertMatchesRegularExpression('/Active connections\s+5/', $display);
    }

    public function testStatsJsonIsTheDataTheAdminEndpointReturns(): void
    {
        $this->answers['debug.stats'] = new ServerControlResult([0 => $this->workerStats(4101, 21.5), 1 => $this->workerStats(4102, 22.0)]);
        $diagnostics = $this->diagnostics();
        $tester = new CommandTester(new ServerStatsCommand($diagnostics));

        $tester->execute(['--json' => true]);

        $endpoint = json_decode((string) (new ServerStatsControllerWithCoroutines($diagnostics))->stats()->getContent(), true);
        self::assertSame($endpoint['data'], json_decode($tester->getDisplay(), true));
        self::assertSame([0, 1], array_column($endpoint['data']['workers'], 'worker_id'));
        self::assertSame(['active_connections' => 5], $endpoint['data']['sse']);
    }

    public function testStatsNamesWorkersThatDidNotAnswerAndFails(): void
    {
        $this->answers['debug.stats'] = new ServerControlResult(
            [0 => $this->workerStats(4101, 21.5)],
            [1 => 'stats unavailable'],
            [2],
        );
        $tester = new CommandTester(new ServerStatsCommand($this->diagnostics()));

        $exitCode = $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('4101', $tester->getDisplay());
        $errors = preg_replace('/\s+/', ' ', $tester->getErrorOutput());
        self::assertStringContainsString('No answer from worker(s) 2.', $errors);
        self::assertStringContainsString('Worker 1 failed: stats unavailable', $errors);
    }

    public function testCoroutinesListOneRowPerWorkerAndNameMissingOnes(): void
    {
        $this->answers['debug.coroutines'] = new ServerControlResult(
            [
                0 => ['coroutines' => ['coroutine_num' => 12, 'coroutine_peak_num' => 40, 'coroutine_last_cid' => 900], 'active_cids' => [1, 2, 3], 'channels' => []],
                1 => [
                    'coroutines' => ['coroutine_num' => 9, 'coroutine_peak_num' => 31, 'coroutine_last_cid' => 700],
                    'active_cids' => [4],
                    'channels' => [['name' => 'seek_signal::abc', 'consumer_num' => 1, 'producer_num' => 0, 'queue_num' => 0, 'capacity' => 1, 'closed' => false]],
                ],
            ],
            [],
            [2],
        );
        $tester = new CommandTester(new ServerCoroutinesCommand($this->diagnostics()));

        $exitCode = $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/^\s*0\s+12\s+40\s+900\s+3\s+0\b/m', $display);
        self::assertMatchesRegularExpression('/^\s*1\s+9\s+31\s+700\s+1\s+1\b/m', $display);
        self::assertStringContainsString('seek_signal::abc', $display);
        self::assertStringContainsString('No answer from worker(s) 2.', preg_replace('/\s+/', ' ', $tester->getErrorOutput()));
    }

    public function testCoroutinesJsonIsWhatTheAdminEndpointReturns(): void
    {
        $this->answers['debug.coroutines'] = new ServerControlResult([0 => ['coroutines' => ['coroutine_num' => 1], 'active_cids' => [1], 'channels' => []]]);
        $diagnostics = $this->diagnostics();
        $tester = new CommandTester(new ServerCoroutinesCommand($diagnostics));

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));

        $endpoint = json_decode((string) (new CoroutineStatsController($diagnostics))()->getContent(), true);
        self::assertSame($endpoint, json_decode($tester->getDisplay(), true));
    }

    public function testWorkersShowTheServerWideFigures(): void
    {
        $this->answers['debug.workers'] = new ServerControlResult([1 => [
            'http_workers' => ['total' => 4, 'idle' => 3, 'active' => 1],
            'task_workers' => ['total' => 2, 'idle' => 2, 'active' => 0],
            'user_workers' => ['total' => 0],
            'transcode_pool' => ['available' => true, 'running' => true, 'worker_count' => 6],
        ]]);
        $tester = new CommandTester(new ServerWorkersCommand($this->diagnostics()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('HTTP workers', $display);
        self::assertMatchesRegularExpression('/total\s+4/', $display);
        self::assertMatchesRegularExpression('/worker count\s+6/', $display);
        self::assertMatchesRegularExpression('/running\s+yes/', $display);
        self::assertSame([['debug.workers', []]], $this->calls);
    }

    public function testSpansListsTheBufferWithTheRequestedLimit(): void
    {
        $this->answers['debug.spans'] = new ServerControlResult([2 => [[
            'trace_id' => str_repeat('a', 32),
            'operation_name' => 'GET api_album_index',
            'start_time_us' => 1_791_472_413_123_456,
            'duration_us' => 15_250,
            'attributes' => ['http.request.method' => 'GET', 'baander.route' => 'api_album_index', 'http.response.status_code' => 200],
        ]]]);
        $tester = new CommandTester(new ServerSpansCommand($this->diagnostics()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '20']));

        self::assertMatchesRegularExpression('/GET api_album_index\s+200\s+15\.3\s+a{32}/', $tester->getDisplay());
        self::assertSame([['debug.spans', ['limit' => 20]]], $this->calls);
    }

    public function testSpansCapsTheLimitLikeTheAdminEndpointAndRejectsANonNumber(): void
    {
        $this->answers['debug.spans'] = new ServerControlResult([0 => []]);
        $tester = new CommandTester(new ServerSpansCommand($this->diagnostics()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '900']));
        self::assertStringContainsString('No spans recorded.', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['--limit' => 'many']));
        self::assertSame([['debug.spans', ['limit' => 500]]], $this->calls);
    }

    public function testSpansClearWithForceEmptiesTheSharedBuffer(): void
    {
        $this->answers['debug.spans.clear'] = new ServerControlResult([0 => true]);
        $tester = new CommandTester(new ServerSpansCommand($this->diagnostics()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--clear' => true, '--force' => true], ['interactive' => false]));

        self::assertSame([['debug.spans.clear', []]], $this->calls);
        self::assertStringContainsString('Emptied the span buffer of every worker.', $tester->getDisplay());
    }

    public function testSpansClearWithoutATerminalNeedsForce(): void
    {
        $tester = new CommandTester(new ServerSpansCommand($this->diagnostics()));

        self::assertSame(Command::INVALID, $tester->execute(['--clear' => true], ['interactive' => false]));

        self::assertSame([], $this->calls);
    }

    public function testSpansClearAsksOnATerminal(): void
    {
        $this->answers['debug.spans.clear'] = new ServerControlResult([0 => true]);
        $tester = new CommandTester(new ServerSpansCommand($this->diagnostics()));
        $tester->setInputs(['no']);

        self::assertSame(Command::FAILURE, $tester->execute(['--clear' => true]));
        self::assertSame([], $this->calls);

        $tester->setInputs(['yes']);
        self::assertSame(Command::SUCCESS, $tester->execute(['--clear' => true]));
        self::assertSame([['debug.spans.clear', []]], $this->calls);
    }

    public function testSpansClearFailsWhenTheServerCouldNotClear(): void
    {
        $this->answers['debug.spans.clear'] = new ServerControlResult([], [0 => 'table gone']);
        $tester = new CommandTester(new ServerSpansCommand($this->diagnostics()));

        self::assertSame(Command::FAILURE, $tester->execute(['--clear' => true, '--force' => true], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('worker 0: table gone', $tester->getErrorOutput());
    }

    /** @return iterable<string, array{\Closure(ServerDiagnostics): Command, array<string, mixed>}> */
    public static function commands(): iterable
    {
        yield 'stats' => [static fn (ServerDiagnostics $d): Command => new ServerStatsCommand($d), []];
        yield 'coroutines' => [static fn (ServerDiagnostics $d): Command => new ServerCoroutinesCommand($d), []];
        yield 'workers' => [static fn (ServerDiagnostics $d): Command => new ServerWorkersCommand($d), []];
        yield 'spans' => [static fn (ServerDiagnostics $d): Command => new ServerSpansCommand($d), []];
        yield 'spans --clear' => [static fn (ServerDiagnostics $d): Command => new ServerSpansCommand($d), ['--clear' => true, '--force' => true]];
    }

    /**
     * @param \Closure(ServerDiagnostics): Command $command
     * @param array<string, mixed> $input
     */
    #[DataProvider('commands')]
    public function testWithNoServerRunningEachCommandFailsWithTheSharedMessage(\Closure $command, array $input): void
    {
        foreach (['debug.stats', 'debug.coroutines', 'debug.workers', 'debug.spans', 'debug.spans.clear'] as $operation) {
            $this->answers[$operation] = new ServerNotRunningException();
        }
        $tester = new CommandTester($command($this->diagnostics()));

        $exitCode = $tester->execute($input, ['capture_stderr_separately' => true, 'interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString(ServerNotRunningException::MESSAGE, $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());
    }

    private function diagnostics(): ServerDiagnostics
    {
        $record = function (string $operation, array $payload): void {
            $this->calls[] = [$operation, $payload];
        };
        $port = new class ($this->answers, $record) implements ServerControlPortInterface {
            /**
             * @param array<string, ServerControlResult|\Throwable> $answers
             * @param \Closure(string, array<string, mixed>): void $record
             */
            public function __construct(private array &$answers, private \Closure $record)
            {
            }

            public function execute(string $operation, array $payload = []): ServerControlResult
            {
                ($this->record)($operation, $payload);
                $answer = $this->answers[$operation] ?? throw new \LogicException('Unexpected operation ' . $operation);
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            }
        };

        $redis = $this->createStub(Redis::class);
        $redis->method('info')->willReturn(['connected_clients' => '7', 'used_memory' => '2097152', 'maxmemory' => '0']);
        $redis->method('ping')->willReturn(true);
        $redis->method('dbSize')->willReturn(42);
        $redis->method('scan')->willReturn(['sse:connections:node-a']);
        $redis->method('mget')->willReturn(['5']);

        return new ServerDiagnostics($port, new RedisClientFactory('redis://127.0.0.1:6379', connectionFactory: static fn (): Redis => $redis));
    }

    /** @return array<string, mixed> */
    private function workerStats(int $pid, float $memory): array
    {
        return [
            'memory' => ['usage' => $memory, 'peak' => $memory + 1, 'real' => 32.0, 'real_peak' => 34.0],
            'process' => ['pid' => $pid, 'uid' => 1000, 'gid' => 1000, 'user' => 'baander', 'uptime' => 3600],
            'swoole' => ['object_num' => 10, 'resource_num' => 3],
            'coroutines' => ['coroutine_num' => 5, 'coroutine_peak_num' => 17],
            'pools' => [['active' => 1, 'free' => 2, 'limit' => 2500]],
        ];
    }
}
