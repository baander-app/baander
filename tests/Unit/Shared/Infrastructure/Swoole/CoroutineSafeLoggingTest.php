<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Tests\Fixtures\Swoole\CoroutineProbe;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler as OriginalStreamHandler;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Monolog\MonologProcessor;
use SwooleBundle\SwooleBundle\Bridge\Monolog\StreamHandler;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\ClassModificationProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\Proxifier;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class CoroutineSafeLoggingTest extends TestCase
{
    public function testProcessorPreservesConfiguredArgumentsAndOnlyReplacesExactStreamHandlers(): void
    {
        $container = new ContainerBuilder();
        $args = ['php://stderr', 'warning', false];
        $stream = $container->register('monolog.handler.stream', OriginalStreamHandler::class)->setArguments($args);
        $rotating = $container->register('monolog.handler.rotating', RotatingFileHandler::class);
        new MonologProcessor()->process($container, new Proxifier($container, new ClassModificationProcessor($container)));

        self::assertSame(StreamHandler::class, $stream->getClass());
        self::assertSame($args, $stream->getArguments());
        self::assertSame('setSwoole', $stream->getMethodCalls()[0][0]);
        self::assertSame(RotatingFileHandler::class, $rotating->getClass());
    }

    public function testWritesCloseAndResetHaveOneOwnerAndQueuedRecordsDoNotRetainContext(): void
    {
        $script = <<<'CHILD'
require $argv[1];
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use SwooleBundle\SwooleBundle\Bridge\Monolog\StreamHandler;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as Adapter;
final class LogStream {
    public $context;
    public static array $owners = [];
    public static string $bytes = '';
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
        self::$owners[] = ['open', Swoole\Coroutine::getCid()]; return true;
    }
    public function stream_write(string $bytes): int {
        self::$owners[] = ['write', Swoole\Coroutine::getCid()];
        Swoole\Coroutine::sleep(0.001);
        self::$bytes .= $bytes; return strlen($bytes);
    }
    public function stream_close(): void { self::$owners[] = ['close', Swoole\Coroutine::getCid()]; }
    public function stream_flush(): bool { return true; }
    public function stream_set_option(int $option, int $arg1, int $arg2): bool { return true; }
    public function stream_stat(): array { return []; }
}
stream_wrapper_register('baanderlog', LogStream::class);
$handler = new StreamHandler('baanderlog://stream');
$handler->setSwoole(new Adapter());
$handler->setFormatter(new LineFormatter("%message%\n"));
$state = (object) ['owner' => null, 'released' => null];
Swoole\Coroutine\run(static function () use ($handler, $state): void {
    $state->owner = Swoole\Coroutine::getCid();
    $context = new class ($state) {
        public function __construct(private object $state) {}
        public function __destruct() { $this->state->released = Swoole\Coroutine::getCid(); }
    };
    $record = new LogRecord(new DateTimeImmutable(), 'baander', Level::Info, 'first', ['owned' => $context]);
    $handler->handle($record);
    unset($record, $context);
    if ($state->released !== $state->owner) { fwrite(STDERR, 'queued context outlived producer ownership'); exit(1); }
    Swoole\Coroutine::create(static function () use ($handler): void {
        $handler->handle(new LogRecord(new DateTimeImmutable(), 'baander', Level::Info, 'second'));
        $handler->reset();
    });
    $handler->close();
});
$ids = array_unique(array_column(LogStream::$owners, 1));
if (count($ids) !== 1 || reset($ids) === -1 || LogStream::$bytes !== "first\nsecond\n") {
    fwrite(STDERR, json_encode([LogStream::$owners, LogStream::$bytes])); exit(2);
}
if (!in_array('close', array_column(LogStream::$owners, 0), true)) { exit(3); }
echo "line output preserved; stream lifecycle has one owner; context released by producer\n";
CHILD;
        $process = CoroutineProbe::create(['-r', $script, dirname(__DIR__, 5) . '/vendor/autoload.php']);
        $process->setTimeout(10);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame("line output preserved; stream lifecycle has one owner; context released by producer\n", $process->getOutput());
    }
}
