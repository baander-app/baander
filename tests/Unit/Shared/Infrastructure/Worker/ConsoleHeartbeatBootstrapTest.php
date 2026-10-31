<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ConsoleHeartbeatBootstrapTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function verbosity(): iterable
    {
        yield 'normal' => [false];
        yield 'verbose' => [true];
    }

    #[DataProvider('verbosity')]
    public function testInactiveAlarmListenerDoesNotConstructRedisHealthDuringProductionConsoleBootstrap(bool $verbose): void
    {
        $root = dirname(__DIR__, 5);
        $nonce = random_bytes(8) |> bin2hex(...);
        $directory = sys_get_temp_dir() . '/baander-console-heartbeat-' . $nonce;
        $dsn = 'redis://default:private-bootstrap-password@redis.baander.app:6379/other';
        $script = <<<'CHILD'
<?php
require $argv[1] . '/vendor/autoload.php';
if (!extension_loaded('pcntl') || !Symfony\Component\Console\SignalRegistry\SignalRegistry::isSupported() || !defined('SIGINT') || !defined('SIGALRM')) {
    throw new RuntimeException('Console signal preloading must be supported by this runtime.');
}
if (getenv('SYMFONY_DOTENV_VARS') !== false) {
    throw new RuntimeException('The console child must not inherit parent Dotenv provenance.');
}
$suppliedRedisUrl = getenv('REDIS_URL');
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($argv[1] . '/.env');
if (!is_string($suppliedRedisUrl) || ($_SERVER['REDIS_URL'] ?? null) !== $suppliedRedisUrl || ($_ENV['REDIS_URL'] ?? null) !== $suppliedRedisUrl || getenv('REDIS_URL') !== $suppliedRedisUrl) {
    throw new RuntimeException('Dotenv replaced the explicitly supplied REDIS_URL.');
}
class ConsoleHeartbeatKernel extends App\Kernel
{
    public function getProjectDir(): string { return $GLOBALS['argv'][1]; }
    public function getCacheDir(): string { return $GLOBALS['argv'][2] . '/cache'; }
    public function getBuildDir(): string { return $this->getCacheDir(); }
    public function getLogDir(): string { return $GLOBALS['argv'][2] . '/log'; }
}
$application = new Symfony\Bundle\FrameworkBundle\Console\Application(new ConsoleHeartbeatKernel('prod', false));
$application->setAutoExit(false);
$command = new Symfony\Component\Console\Command\Command('fixture:console-heartbeat');
$command->setCode(static function (Symfony\Component\Console\Input\InputInterface $input, Symfony\Component\Console\Output\OutputInterface $output): int {
    if (!is_callable(pcntl_signal_get_handler(SIGALRM))) {
        throw new RuntimeException('Console signal handlers were not registered before command execution.');
    }
    $output->writeln('Console heartbeat bootstrap ready.');
    return Symfony\Component\Console\Command\Command::SUCCESS;
});
$application->addCommand($command);
$arguments = ['bin/console', 'fixture:console-heartbeat', '--no-interaction', ...array_slice($argv, 3)];
exit($application->run(new Symfony\Component\Console\Input\ArgvInput($arguments)));
CHILD;
        $filesystem = new Filesystem();
        try {
            $filesystem->mkdir($directory);
            self::assertNotFalse(file_put_contents($directory . '/console.php', $script));
            $process = new Process([
                PHP_BINARY, '-d', 'zend.exception_ignore_args=0', $directory . '/console.php',
                $root, $directory, ...($verbose ? ['-vvv'] : []),
            ], cwd: $root, env: [
                'APP_ENV' => 'prod',
                'APP_DEBUG' => '0',
                'REDIS_URL' => $dsn,
                'SYMFONY_DOTENV_VARS' => false,
                'MESSENGER_TRANSPORT_DSN' => 'redis://redis.baander.app:6379/messages/baander',
                'DATABASE_URL' => 'postgresql://worker:unreachable@127.0.0.1:1/worker?serverVersion=18',
                'XDEBUG_MODE' => 'off',
                'SHELL_VERBOSITY' => '0',
            ]);
            $process->setTimeout(90);
            $process->run();
            $output = $process->getOutput() . $process->getErrorOutput();

            self::assertTrue($process->isSuccessful(), $output);
            self::assertStringContainsString('Console heartbeat bootstrap ready.', $output);
            self::assertStringNotContainsString($dsn, $output);
            self::assertStringNotContainsString('private-bootstrap-password', $output);
        } finally {
            $filesystem->remove($directory);
        }
    }
}
