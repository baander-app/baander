<?php

declare(strict_types=1);

require dirname(__DIR__, 5) . '/vendor/autoload.php';

use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\EndResponseProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\ResponseHeadersAndStatusProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\SwooleBinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

$file = tempnam(sys_get_temp_dir(), 'baander-sendfile-');
file_put_contents($file, '0123456789');
try {
    Swoole\Coroutine\run(function () use ($file): void {
        $server = new Server('127.0.0.1', 0);
        $server->set(['http_compression' => false]);
        $server->handle('/api/stream/track', static function (SwooleRequest $request, SwooleResponse $transport) use ($file): void {
            $foundation = Request::create('https://api.baander.app/api/stream/track', $request->server['request_method']);
            foreach ($request->header as $name => $value) {
                $foundation->headers->set($name, $value);
            }
            $response = (new SwooleBinaryFileResponse($file))->prepare($foundation);
            $response->headers->set('Content-Type', 'audio/mpeg');
            (new ResponseHeadersAndStatusProcessor(new EndResponseProcessor()))->process($response, $transport);
        });
        Swoole\Coroutine::create(static function () use ($server): void { $server->start(); });
        try {
            foreach ([
                ['GET', 'bytes=2-4', 206, '234', '3'],
                ['HEAD', 'bytes=2-4', 200, '', '10'],
                ['GET', 'bytes=10-', 416, '', '0'],
                ['GET', 'bytes=abc-def', 200, '0123456789', '10'],
            ] as [$method, $range, $status, $body, $length]) {
                $client = new Client('127.0.0.1', $server->port);
                $client->set(['timeout' => 2]);
                $client->setMethod($method);
                $client->setHeaders(['Host' => 'api.baander.app', 'Range' => $range, 'Accept-Encoding' => 'identity']);
                try {
                    if (!$client->execute('/api/stream/track') || $client->statusCode !== $status ||
                        $client->body !== $body || ($client->headers['content-length'] ?? null) !== $length) {
                        throw new RuntimeException('Unexpected file transport response: ' . json_encode([
                            $method, $range, $client->statusCode, $client->body, $client->headers,
                        ], JSON_THROW_ON_ERROR));
                    }
                } finally {
                    $client->close();
                }
            }
        } finally {
            $server->shutdown();
        }
    });
} finally {
    unlink($file);
}
echo "Real Swoole file transport: 4 cases passed, server shut down.\n";
