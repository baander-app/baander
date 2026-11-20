<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\LogViewer;

use App\Kernel;
use App\Shared\Infrastructure\LogViewer\Http\ConfiguredLogNodeClient;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Runtime;

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
        $originalHooks = Runtime::getHookFlags();
        try {
            $client = new ConfiguredLogNodeClient(json_encode([['id' => 'local', 'url' => 'http://'.$address, 'tokenFile' => $tokenFile]], JSON_THROW_ON_ERROR));
            Runtime::enableCoroutine(Kernel::LOG_QUERY_HOOKS);
            $yielded = false;
            $response = null;
            \Swoole\Coroutine\run(static function () use ($client, &$yielded, &$response): void {
                Coroutine::create(static function () use (&$yielded): void {
                    Coroutine::sleep(0.01);
                    $yielded = true;
                });
                $response = $client->get('local', '/entries', ['channels' => ['app', 'security,audit']]);
                self::assertTrue($yielded, 'HTTP must yield to other request coroutines while the node responds.');
            });
            self::assertSame(['channels' => ['app', 'security,audit'], 'authenticated' => true], $response);
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        } finally {
            Runtime::setHookFlags($originalHooks);
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            unlink($tokenFile);
        }
    }
}
