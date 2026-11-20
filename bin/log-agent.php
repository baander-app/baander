#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Shared\Infrastructure\LogViewer\Agent\AgentConfiguration;
use App\Shared\Infrastructure\LogViewer\Agent\AgentProtocol;
use App\Shared\Infrastructure\LogViewer\Agent\CheckpointExchange;
use App\Shared\Infrastructure\LogViewer\Agent\Collector;
use App\Shared\Infrastructure\LogViewer\LogIndex;
use Baander\Core\EnvironmentVariable;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    $config = AgentConfiguration::load(getenv(EnvironmentVariable::BAANDER_LOG_AGENT_CONFIG) ?: '/etc/baander/log-agent.json');
    if ('--checkpoint' === ($argv[1] ?? null)) {
        $containerId = $argv[2] ?? '';
        $timeout = 5.0;
        foreach (array_slice($argv, 3) as $argument) {
            if (str_starts_with($argument, '--timeout=')) {
                $timeout = (float) substr($argument, 10);
            }
        }
        $body = json_encode(['containerId' => $containerId, 'timeoutSeconds' => $timeout], JSON_THROW_ON_ERROR);
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Authorization: Bearer '.$config->token(true)."\r\nContent-Type: application/json\r\n", 'content' => $body, 'timeout' => min(12, $timeout + 1), 'ignore_errors' => true]]);
        $response = @file_get_contents('http://127.0.0.1:'.$config->get('listenPort', 9509).'/checkpoint', false, $context);
        $result = false === $response ? [] : json_decode($response, true);
        if (true !== ($result['complete'] ?? false)) {
            fwrite(STDERR, "Final log collection incomplete.\n");
            exit(1);
        }
        fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
        exit(0);
    }
    if (!extension_loaded('swoole') || !extension_loaded('sqlite3')) {
        throw new RuntimeException('The log agent requires Swoole and SQLite3.');
    }
    // Verify credentials before binding or starting a collector.
    $config->token();
    $config->token(true);
    $index = new LogIndex($config->get('databasePath'), false, $config->get('maxStorageBytes', 1073741824));
    unset($index);
    $server = new Swoole\Http\Server($config->get('listenHost', '0.0.0.0'), $config->get('listenPort', 9509));
    $server->set(['worker_num' => 1, 'enable_coroutine' => true, 'http_parse_post' => false, 'package_max_length' => 32768, 'log_file' => '/dev/stderr']);
    $process = new Swoole\Process(static function () use ($config): void {
        $running = true;
        Swoole\Process::signal(SIGTERM, static function () use (&$running): void {
            $running = false;
        });
        Swoole\Process::signal(SIGINT, static function () use (&$running): void {
            $running = false;
        });
        $collector = new Collector($config, new LogIndex($config->get('databasePath'), false, $config->get('maxStorageBytes', 1073741824)));
        $exchange = new CheckpointExchange($config->get('databasePath'));
        while ($running) {
            try {
                $exchange->process($collector);
                $collector->tick();
            } catch (Throwable) {
                fwrite(STDERR, "Log collector cycle failed.\n");
            }
            usleep(250000);
        }
    }, false, 0, false);
    $server->addProcess($process);
    $server->on('request', static function (Swoole\Http\Request $request, Swoole\Http\Response $response) use ($config): void {
        $response->header('Content-Type', 'application/json');
        $response->header('Cache-Control', 'no-store');
        $path = $request->server['request_uri'] ?? '';
        $authorization = $request->header['authorization'] ?? '';
        if ('/checkpoint' === $path) {
            if ('POST' !== ($request->server['request_method'] ?? '') || !in_array($request->server['remote_addr'] ?? '', ['127.0.0.1', '::1'], true) || !hash_equals('Bearer '.$config->token(true), $authorization)) {
                $response->status(403);
                $response->end('{"error":"Checkpoint access denied."}');
                return;
            }
            try {
                $input = json_decode($request->rawContent(), true, 8, JSON_THROW_ON_ERROR);
                if (!is_array($input) || [] !== array_diff(array_keys($input), ['containerId', 'timeoutSeconds'])) {
                    throw new InvalidArgumentException('Invalid checkpoint fields.');
                }
                $timeout = $input['timeoutSeconds'] ?? 5;
                if (!is_int($timeout) && !is_float($timeout)) {
                    throw new InvalidArgumentException('Invalid checkpoint deadline.');
                }
                $exchange = new CheckpointExchange($config->get('databasePath'));
                $id = $exchange->request($input['containerId'] ?? '', $timeout);
                $deadline = microtime(true) + $timeout;
                $result = null;
                while (microtime(true) < $deadline && null === ($result = $exchange->result($id))) {
                    Swoole\Coroutine::sleep(0.05);
                }
                $result ??= ['complete' => false, 'error' => 'Final collection deadline expired; history may contain a gap.'];
                $response->status($result['complete'] ? 200 : 503);
                $response->end(json_encode($result, JSON_THROW_ON_ERROR));
            } catch (Throwable) {
                $response->status(422);
                $response->end('{"error":"Invalid checkpoint request."}');
            }
            return;
        }
        $protocol = new AgentProtocol($config, new LogIndex($config->get('databasePath'), true));
        [$status, $body] = $protocol->handle($request->server['request_method'] ?? '', $path, $request->get ?? [], $authorization);
        $response->status($status);
        $response->end(json_encode($body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    });
    $server->start();
} catch (Throwable) {
    fwrite(STDERR, "Log agent failed to start or checkpoint.\n");
    exit(1);
}
