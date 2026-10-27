<?php

declare(strict_types=1);

namespace Baander\Tooling\Backend;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class StackIsolation
{
    private const string DATABASE_URL = 'postgresql://baander:test-only@postgres:5432/functional_test?serverVersion=18&charset=utf8';
    private const string REDIS_URL = 'redis://default:test-only@redis:6379';
    private const string CACHE = '/var/www/html/var/cache/test/stack-isolation-marker';
    private const string SOCKET = '/tmp/baander-stack-isolation.sock';
    private const string CONNECT = <<<'PHP_CODE'
$database = parse_url(getenv('DATABASE_URL'));
$db = new PDO(
    'pgsql:host=' . $database['host'] . ';port=' . $database['port'] . ';dbname=' . ltrim($database['path'], '/'),
    $database['user'],
    $database['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$address = parse_url(getenv('REDIS_URL'));
$redis = new Redis();
$redis->connect($address['host'], $address['port'], 2);
$redis->auth([$address['user'], $address['pass']]);
PHP_CODE;
    /** @var array<string,string> */
    private array $apps = [];
    /** @var array<string,string> */
    private array $projects = [];
    /** @var list<array{check:string,passed:bool,actual:mixed,expected:mixed}> */
    public array $results = [];
    private int $sequence = 0;

    public function __construct(private readonly Runtime $runtime) {}

    /** @param list<string> $arguments */
    private function command(array $arguments, bool $check = true): \Baander\Tooling\Process\ProcessResult
    {
        $log = $this->runtime->artifacts . '/command-' . sprintf('%03d', ++$this->sequence) . '.log';
        try {
            $result = $this->runtime->docker($arguments, check: false);
            file_put_contents($log, json_encode($arguments, JSON_THROW_ON_ERROR) . "\n" . $result->stdout . $result->stderr);
        } catch (Throwable $error) {
            file_put_contents($log, json_encode($arguments, JSON_THROW_ON_ERROR) . "\n" . $error->getMessage() . "\n");
            throw $error;
        }
        if ($check && $result->exitCode !== 0) {
            throw new RuntimeException('Command failed (' . $result->exitCode . '); see ' . $log);
        }
        return $result;
    }

    private function php(string $stack, string $code): string
    {
        return $this->command([
            'exec', '--user', 'www-data', $this->apps[$stack],
            'php', '-d', 'memory_limit=512M', '-r', $code,
        ])->stdout |> trim(...);
    }

    private function expect(string $name, mixed $actual, mixed $expected): void
    {
        $passed = $actual === $expected;
        $this->results[] = ['check' => $name, 'passed' => $passed, 'actual' => $actual, 'expected' => $expected];
        if (!$passed) {
            throw new RuntimeException($name . ': expected ' . json_encode($expected) . ', received ' . json_encode($actual));
        }
    }

    private function seed(string $stack): void
    {
        $cache = var_export(self::CACHE, true);
        $socket = var_export(self::SOCKET, true);
        $marker = var_export($stack, true);
        $this->php($stack, self::CONNECT . <<<PHP_CODE

\$db->exec('CREATE TABLE isolation_probe (marker text NOT NULL)');
\$db->exec("INSERT INTO isolation_probe VALUES ('$stack')");
\$redis->set('isolation-probe', $marker);
if (!is_dir(dirname($cache))) { mkdir(dirname($cache), 0777, true); }
file_put_contents($cache, $marker);
PHP_CODE);
        $this->command(['exec', '-d', '--user', 'www-data', $this->apps[$stack], 'php', '-d', 'memory_limit=512M', '-r', <<<PHP_CODE
\$server = stream_socket_server('unix://' . $socket, \$error, \$message);
if (!\$server) { throw new RuntimeException(\$message); }
while (\$connection = stream_socket_accept(\$server, -1)) { fwrite(\$connection, $marker); fclose(\$connection); }
PHP_CODE]);
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            if ($this->php($stack, 'echo file_exists(' . $socket . ") ? 'ready' : 'waiting';") === 'ready') {
                return;
            }
            usleep(100_000);
        }
        throw new RuntimeException('Stack ' . $stack . ' socket did not become ready');
    }

    private function probe(string $stack): string
    {
        return $this->php($stack, self::CONNECT . "echo \$db->query('SELECT marker FROM isolation_probe')->fetchColumn() . ':' . \$redis->get('isolation-probe');");
    }

    public function run(): void
    {
        foreach (['left', 'right'] as $stack) {
            $project = $this->runtime->startServices($stack);
            $this->projects[$stack] = $project;
            $this->apps[$stack] = $this->runtime->createApp($stack . '-app', $project, true, true);
            $this->seed($stack);
            $this->expect($stack . ' initial markers', $this->probe($stack), $stack . ':' . $stack);
            $child = self::CONNECT . <<<'PHP_CODE'
                echo json_encode([
                    getenv('DATABASE_URL'),
                    getenv('OUTBOX_TEST_DATABASE_URL'),
                    getenv('REDIS_URL'),
                    getenv('MESSENGER_TEST_REDIS_DSN'),
                    getenv('MESSENGER_TRANSPORT_DSN'),
                    $db->query('SELECT marker FROM isolation_probe')->fetchColumn(),
                    $redis->get('isolation-probe'),
                ]);
                PHP_CODE;
            $parent = sprintf(<<<'PHP_CODE'
                $p = proc_open(
                    [PHP_BINARY, '-d', 'memory_limit=512M', '-r', %s],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                echo stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                if (proc_close($p)) {
                    fwrite(STDERR, $errors);
                    exit(1);
                }
                PHP_CODE, var_export($child, true));
            $this->expect(
                $stack . ' nested PHP environment and services',
                json_decode($this->php($stack, $parent), true, flags: JSON_THROW_ON_ERROR),
                [self::DATABASE_URL, self::DATABASE_URL, self::REDIS_URL, self::REDIS_URL, self::REDIS_URL . '/messages', $stack, $stack],
            );
        }
        $count = "echo \$db->query('SELECT count(*) FROM isolation_probe')->fetchColumn();";
        $this->php('left', self::CONNECT . "\$db->exec('TRUNCATE isolation_probe');" . $count);
        $this->expect('left database truncated', $this->php('left', self::CONNECT . $count), '0');
        $this->expect('peer survives database truncate', $this->probe('right'), 'right:right');
        $postgres = $this->runtime->serviceContainer($this->projects['left'], 'postgres');
        $this->command(['stop', '--time', '1', $postgres]);
        $failed = $this->command(['exec', '--user', 'www-data', $this->apps['left'], 'php', '-d', 'memory_limit=512M', '-r', self::CONNECT], false);
        $this->expect('left database outage observed', $failed->exitCode !== 0, true);
        $this->expect('peer survives database outage', $this->probe('right'), 'right:right');
        $redis = "\$redis = new Redis(); \$redis->connect('redis', 6379, 2); \$redis->auth(['default', 'test-only']);";
        $this->php('left', $redis . '$redis->flushAll();');
        $this->expect('left Redis flushed', $this->php('left', $redis . 'echo $redis->dbSize();'), '0');
        $this->expect('peer survives Redis FLUSHALL', $this->probe('right'), 'right:right');
        $this->php('left', $redis . "\$redis->rawCommand('CLIENT', 'PAUSE', '5000', 'ALL');");
        $started = Runtime::now();
        $this->expect('peer survives Redis CLIENT PAUSE', $this->probe('right'), 'right:right');
        $this->expect('peer Redis responds during pause', Runtime::now() - $started < 2, true);
        $this->php('left', $redis . "\$redis->setOption(Redis::OPT_READ_TIMEOUT, 10); echo \$redis->ping() ? 'ready' : 'failed';");
        $this->expect('left Redis pause observed', Runtime::now() - $started >= 3, true);
        $cache = var_export(self::CACHE, true);
        $socket = var_export(self::SOCKET, true);
        foreach (['left', 'right'] as $stack) {
            $this->expect($stack . ' identical cache path', $this->php($stack, 'echo file_get_contents(' . $cache . ');'), $stack);
            $this->expect(
                $stack . ' identical socket path',
                $this->php($stack, '$s = stream_socket_client("unix://" . ' . $socket . '); echo stream_get_contents($s);'),
                $stack,
            );
        }
        $this->php('left', 'unlink(' . $cache . '); unlink(' . $socket . ');');
        $this->expect(
            'left cache and socket removed',
            $this->php('left', 'echo (!file_exists(' . $cache . ') && !file_exists(' . $socket . ")) ? 'removed' : 'present';"),
            'removed',
        );
        $this->expect('peer cache survives deletion', $this->php('right', 'echo file_get_contents(' . $cache . ');'), 'right');
        $this->expect(
            'peer socket survives deletion',
            $this->php('right', '$s = stream_socket_client("unix://" . ' . $socket . '); echo stream_get_contents($s);'),
            'right',
        );
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        $artifacts = null;
        $timeout = 300;
        while ($arguments !== []) {
            $option = array_shift($arguments);
            if ($option === '--help' || $option === '-h') {
                echo "Usage: test-backend-stack-isolation.php [--artifacts DIRECTORY] [--timeout SECONDS]\n";
                return 0;
            }
            if (str_contains($option, '=')) {
                [$option, $value] = explode('=', $option, 2);
            } else {
                $value = array_shift($arguments);
            }
            if ($value === null) {
                throw new InvalidArgumentException('Missing value for ' . $option);
            }
            match ($option) {
                '--artifacts' => $artifacts = $value,
                '--timeout' => $timeout = Coordinator::positiveInteger($value, '--timeout'),
                default => throw new InvalidArgumentException('Unknown option: ' . $option),
            };
        }
        $artifacts ??= sys_get_temp_dir() . '/baander-stack-isolation-' . bin2hex(random_bytes(8));
        if (!is_dir($artifacts) && !mkdir($artifacts, 0700, true)) {
            throw new RuntimeException('Cannot create stack-isolation artifacts');
        }
        $runtime = new Runtime($artifacts, $timeout, $root);
        $runtime->image = $runtime->environment['BAANDER_TEST_IMAGE'] ?? 'baander-ci-prewarm:local';
        $regression = new self($runtime);
        $error = null;
        try {
            $runtime->connect();
            $regression->run();
        } catch (Throwable $failure) {
            $error = $failure->getMessage();
        } finally {
            try {
                $runtime->cleanup();
            } catch (Throwable $failure) {
                $runtime->cleanupErrors[] = $failure->getMessage();
            }
            $runtime->close();
            file_put_contents($artifacts . '/results.json', json_encode(['checks' => $regression->results, 'error' => $error,
                'cleanup_errors' => $runtime->cleanupErrors, 'projects' => $runtime->projects, ...$runtime->resources], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        }
        if ($error !== null || $runtime->cleanupErrors !== []) {
            fwrite(STDERR, 'Stack isolation failed: ' . ($error ?? implode('; ', $runtime->cleanupErrors)) . '; artifacts: ' . $artifacts . "\n");
            return 1;
        }
        printf("Stack isolation passed: %d checks; artifacts: %s\n", count($regression->results), $artifacts);
        return 0;
    }
}
