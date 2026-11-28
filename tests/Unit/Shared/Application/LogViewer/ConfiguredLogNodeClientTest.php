<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\LogViewer;

use App\Tests\Fixtures\Swoole\CoroutineProbe;
use PHPUnit\Framework\TestCase;

final class ConfiguredLogNodeClientTest extends TestCase
{
    public function testConfiguredServerHooksLetAuthenticatedHttpYieldAndPreserveChannelArrays(): void
    {
        if (!extension_loaded('swoole') || !function_exists('pcntl_fork')) {
            self::markTestSkipped('The production Swoole and pcntl extensions are required.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        self::assertIsString($address);
        $tokenFile = tempnam(sys_get_temp_dir(), 'baander-log-token-');
        self::assertIsString($tokenFile);
        file_put_contents($tokenFile, 'local-test-credential');
        chmod($tokenFile, 0600);
        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            $connection = stream_socket_accept($listener, 5);
            if ($connection === false) {
                exit(1);
            }
            $request = fgets($connection);
            $headers = '';
            while (false !== ($line = fgets($connection)) && $line !== "\r\n") {
                $headers .= $line;
            }
            parse_str(parse_url(explode(' ', $request ?: '')[1] ?? '', PHP_URL_QUERY) ?: '', $parameters);
            usleep(100000);
            $body = json_encode(['channels' => $parameters['channels'] ?? [], 'authenticated' => str_contains($headers, 'Authorization: Bearer local-test-credential')], JSON_THROW_ON_ERROR);
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
            fclose($connection);
            fclose($listener);
            exit(0);
        }
        fclose($listener);
        try {
            $script = <<<'PHP'
                require $argv[1];

                $client = new App\Shared\Infrastructure\LogViewer\Http\ConfiguredLogNodeClient($argv[2]);
                Swoole\Runtime::enableCoroutine(App\Shared\Infrastructure\Swoole\HookFlags::logQuery());
                Swoole\Coroutine\run(static function () use ($client): void {
                    $yielded = false;
                    Swoole\Coroutine::create(static function () use (&$yielded): void {
                        Swoole\Coroutine::sleep(0.01);
                        $yielded = true;
                    });
                    $response = $client->get('local', '/entries', ['channels' => ['app', 'security,audit']]);
                    echo json_encode(['yielded' => $yielded, 'response' => $response], JSON_THROW_ON_ERROR);
                });
                PHP;
            $nodes = json_encode([['id' => 'local', 'url' => 'http://'.$address, 'tokenFile' => $tokenFile]], JSON_THROW_ON_ERROR);
            $probe = CoroutineProbe::create(['-r', $script, dirname(__DIR__, 5).'/vendor/autoload.php', $nodes]);
            $probe->setTimeout(5);
            self::assertSame(0, $probe->run(), $probe->getErrorOutput());
            $result = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($result['yielded'], 'HTTP must yield to other request coroutines while the node responds.');
            self::assertSame(['channels' => ['app', 'security,audit'], 'authenticated' => true], $result['response']);
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        } finally {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            unlink($tokenFile);
        }
    }
}
