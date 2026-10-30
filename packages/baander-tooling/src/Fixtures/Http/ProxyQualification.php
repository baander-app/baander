<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Http;

use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Process\ProcessSpec;
use RuntimeException;
use Throwable;

final class ProxyQualification
{
    private const string HOST = 'proxy.baander.app';

    public static function host(string $root): int
    {
        $runtime = new Runtime($root, budget: 'http');
        try {
            $image = $runtime->image('proxy')->id;
            return ContainerQualification::run(
                $runtime,
                'baander-ws-proxy',
                [
                    '--pull=never',
                    '--network', 'none',
                    '--user', '0:0',
                    '--memory', '256m',
                    '--cpus', '1',
                    '--pids-limit', '96',
                    '--entrypoint', 'sleep',
                    $image, '180',
                ],
                static function (Runtime $drill, string $container, string $artifacts) use ($root): void {
                    $archive = $artifacts . '/proxy.tar';
                    $drill->run([
                        'tar',
                        '-cf', $archive,
                        '-C', $root,
                        'docker/prod/nginx.conf',
                        'docker/dev/nginx.conf',
                        'docker/dev/nginx.conf.template',
                        'scripts/bootstrap.php',
                        'scripts/vendor',
                        'scripts/test-websocket-proxy-logs.php',
                        'packages/baander-tooling',
                    ]);
                    $drill->docker()->run(['cp', $archive, $container . ':/tmp/proxy.tar'], timeout: 10);
                    $drill->docker()->run(
                        [
                            'exec',
                            $container,
                            'sh',
                            '-ec',
                            'mkdir -m 0700 -p /tmp/ws-proxy /tmp/ws-proxy/repository; tar -xf /tmp/proxy.tar -C /tmp/ws-proxy/repository; rm /tmp/proxy.tar',
                        ],
                        timeout: 10,
                    );
                    unlink($archive);
                    $result = $drill->docker()->run(
                        [
                            'exec',
                            $container,
                            'php',
                            '/tmp/ws-proxy/repository/scripts/test-websocket-proxy-logs.php',
                            '--inside', '/tmp/ws-proxy',
                        ],
                        timeout: 120,
                    );
                    fwrite(STDOUT, $result->stdout);
                    if ($result->stderr !== '') {
                        fwrite(STDERR, $result->stderr);
                    }
                },
                ['/tmp/ws-proxy' => 'proxy'],
            );
        } finally {
            $runtime->close();
        }
    }

    /** @param list<string> $secrets */
    public static function assertLogs(string $variant, array $secrets, string $access, string $errors, string $globalErrors): void
    {
        foreach ($secrets as $secret) {
            if (str_contains($access . $errors . $globalErrors, $secret)) {
                throw new RuntimeException('A request credential reached proxy logs');
            }
        }
        if (preg_match_all('/"GET \/api\/ws HTTP\/1\.1" 502 /', $access) !== 4) {
            throw new RuntimeException('Missing safe upgrade failure access records');
        }
        if ($variant !== 'prod' && preg_match_all('/"GET \/api\/ws HTTP\/1\.1" 307 /', $access) !== 4) {
            throw new RuntimeException('Missing safe redirect access records');
        }
        if (!str_contains($errors, '/api/proxy-observability-control')) {
            throw new RuntimeException('Ordinary-route upstream diagnostics disappeared');
        }
        if (str_contains($errors . $globalErrors, '/api/ws')) {
            throw new RuntimeException('Upgrade errors retained a raw request URI');
        }
        if (str_contains($access, '?')) {
            throw new RuntimeException('A query string reached the access log');
        }
    }

    /** @param array<string,mixed> $response */
    public static function assertEcho(string $authority, string $route, array $response): void
    {
        if (($response['path'] ?? null) !== $route || !is_array($response['headers'] ?? null)) {
            throw new RuntimeException('Proxy changed the forwarded route or returned invalid echo headers');
        }
        $headers = array_change_key_case($response['headers']);
        foreach (['host', 'x-forwarded-host'] as $name) {
            if (($headers[$name] ?? null) !== $authority) {
                throw new RuntimeException($route . ': ' . $name . ' lost public authority');
            }
        }
        if (($headers['x-forwarded-proto'] ?? null) !== 'https') {
            throw new RuntimeException($route . ': spoofed scheme reached app');
        }
        if (array_key_exists('x-forwarded-port', $headers)) {
            throw new RuntimeException($route . ': spoofed port reached app');
        }
    }

    /** @return non-empty-list<string> */
    private static function curl(string $workspace, int $port): array
    {
        return [
            'curl',
            '--silent',
            '--show-error',
            '--max-time', '5',
            '--noproxy', '*',
            '--resolve', self::HOST . ':' . $port . ':127.0.0.1',
            '--cacert', '/etc/nginx/certificates/cert.pem',
            '-o', $workspace . '/response.body',
            '-w', '%{http_code}',
        ];
    }

    private static function shutdown(Runtime $drill, string $workspace): void
    {
        $drill->run(['nginx', '-c', $workspace . '/nginx.conf', '-s', 'quit'], timeout: 5);
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            clearstatcache(true, $workspace . '/nginx.pid');
            if (!file_exists($workspace . '/nginx.pid')) {
                return;
            }
            usleep(100_000);
        }
        throw new RuntimeException('nginx did not stop within five seconds');
    }

    private static function startNginx(Runtime $drill, string $workspace): void
    {
        // nginx daemonizes into a new session. Its PID file and disposable
        // container own shutdown; signalling the launcher group would race it.
        $drill->executor->run(new ProcessSpec(
            argv: ['nginx', '-c', $workspace . '/nginx.conf'],
            environment: $drill->environment,
            replaceEnvironment: true,
            timeout: 5,
            logPath: $workspace . '/nginx-start.log',
            processGroup: false,
        ));

    }

    private static function prepare(string $root, string $workspace, Runtime $drill): void
    {
        foreach (['/etc/nginx/certificates', '/var/log/nginx'] as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
        }
        $drill->run(['nginx', '-v']);
        $drill->run(
            [
                'openssl',
                'req',
                '-x509',
                '-newkey', 'rsa:2048',
                '-nodes',
                '-days', '1',
                '-subj', '/CN=' . self::HOST,
                '-addext', 'subjectAltName=DNS:' . self::HOST,
                '-keyout', '/etc/nginx/certificates/key.pem',
                '-out', '/etc/nginx/certificates/cert.pem',
            ],
            logPath: $workspace . '/certificate.log',
        );
        foreach ([
            'prod' => 'docker/prod/nginx.conf',
            'dev' => 'docker/dev/nginx.conf',
            'template' => 'docker/dev/nginx.conf.template',
        ] as $name => $source) {
            $content = file_get_contents($root . '/' . $source);
            if ($content === false || substr_count($content, 'server app:9501;') !== 1) {
                throw new RuntimeException('Expected exactly one app upstream in ' . $source);
            }
            $content = str_replace(['server app:9501;', '${SERVER_NAME}'], ['server 127.0.0.1:9501;', self::HOST], $content);
            file_put_contents($workspace . '/' . $name . '.conf', $content);
        }
        file_put_contents($workspace . '/nginx.conf', <<<NGINX
            worker_processes 1;
            pid $workspace/nginx.pid;
            error_log $workspace/global-error.log;
            events { worker_connections 64; }
            http { include $workspace/selected.conf; }
            NGINX);
        foreach (['/var/log/nginx/access.log', '/var/log/nginx/error.log'] as $path) {
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
        }
    }

    private static function variant(string $name, string $workspace, Runtime $drill): void
    {
        copy($workspace . '/' . $name . '.conf', $workspace . '/selected.conf');
        foreach (['/var/log/nginx/access.log', '/var/log/nginx/error.log', $workspace . '/global-error.log'] as $path) {
            file_put_contents($path, '');
        }
        $drill->run(['nginx', '-t', '-c', $workspace . '/nginx.conf'], logPath: $workspace . '/validation.log');
        self::startNginx($drill, $workspace);
        $scheme = $name === 'prod' ? 'http' : 'https';
        $port = $name === 'prod' ? 80 : 443;
        $curl = self::curl($workspace, $port);
        $control = $drill->run([...$curl, $scheme . '://' . self::HOST . '/api/proxy-observability-control'])->stdout;
        if ($control !== '502') {
            throw new RuntimeException($name . ': control did not reach the refused upstream');
        }
        $ticket = bin2hex(random_bytes(32));
        $duplicate = bin2hex(random_bytes(32));
        $encoded = sprintf('%%%02X', ord($ticket[0])) . substr($ticket, 1);
        $referrer = 'proxy-referrer-secret-canary';
        $agent = 'proxy-user-agent-secret-canary';
        foreach ([
            'ticket=' . $ticket,
            'ticket=' . $ticket . '&ticket=' . $duplicate,
            '%74ick%65t=' . $ticket,
            'ticket=' . $encoded,
        ] as $query) {
            $status = $drill->run([
                ...$curl,
                '-H', 'Origin: https://baander.app',
                '-H', 'Upgrade: websocket',
                '-H', 'Connection: Upgrade',
                '-H', 'Sec-WebSocket-Version: 13',
                '-H', 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==',
                '-H', 'Referer: https://baander.app/player?ticket=' . $referrer,
                '-H', 'User-Agent: ' . $agent,
                $scheme . '://' . self::HOST . '/api/ws?' . $query,
            ])->stdout;
            if ($status !== '502') {
                throw new RuntimeException($name . ': upgrade did not return 502');
            }
            if ($name !== 'prod') {
                $redirect = $drill->run([
                    ...self::curl($workspace, 80),
                    '-H', 'Referer: https://baander.app/player?ticket=' . $referrer,
                    'http://' . self::HOST . '/api/ws?' . $query,
                ])->stdout;
                if ($redirect !== '307') {
                    throw new RuntimeException($name . ': HTTP redirect did not return 307');
                }
            }
        }
        self::shutdown($drill, $workspace);
        $access = (string) file_get_contents('/var/log/nginx/access.log');
        $errors = (string) file_get_contents('/var/log/nginx/error.log');
        $global = (string) file_get_contents($workspace . '/global-error.log');
        file_put_contents($workspace . '/' . $name . '-access.log', $access);
        file_put_contents($workspace . '/' . $name . '-error.log', $errors);
        file_put_contents($workspace . '/' . $name . '-global-error.log', $global);
        self::assertLogs($name, [$ticket, $duplicate, $encoded, $referrer, $agent], $access, $errors, $global);
        printf("PASS: %s: nginx -t, four query forms return upstream 502 with safe logs; ordinary-route diagnostics retained\n", $name);
    }

    public static function inside(string $root, string $workspace): int
    {
        $drill = new Runtime($root, budget: 'http');
        $echo = null;
        $started = hrtime(true) / 1e9;
        try {
            self::prepare($root, $workspace, $drill);
            foreach (['prod', 'dev', 'template'] as $name) {
                self::variant($name, $workspace, $drill);
            }
            $echo = $drill->start(
                arguments: [PHP_BINARY, $root . '/scripts/test-websocket-proxy-logs.php', '--echo', $workspace . '/echo-ready'],
                timeout: 60,
                logPath: $workspace . '/echo.log',
            );
            $ready = false;
            for ($attempt = 0; $attempt < 50; ++$attempt) {
                clearstatcache(true, $workspace . '/echo-ready');
                if (file_exists($workspace . '/echo-ready')) {
                    $ready = true;
                    break;
                }
                usleep(100_000);
            }
            if (!$ready) {
                throw new RuntimeException('Echo upstream did not start');
            }
            copy($workspace . '/template.conf', $workspace . '/selected.conf');
            $drill->run(['nginx', '-t', '-c', $workspace . '/nginx.conf'], logPath: $workspace . '/validation.log');
            self::startNginx($drill, $workspace);
            foreach ([self::HOST . ':18443', self::HOST] as $authority) {
                foreach (['/', '/api/ws', '/api/stream/track'] as $route) {
                    $curl = self::curl($workspace, 443);
                    $drill->run([
                        ...$curl,
                        '--fail',
                        '-H', 'Host: ' . $authority,
                        '-H', 'X-Forwarded-Host: attacker.baander.app:9443',
                        '-H', 'X-Forwarded-Proto: http',
                        '-H', 'X-Forwarded-Port: 9443',
                        'https://' . self::HOST . $route,
                    ]);
                    $response = file_get_contents($workspace . '/response.body')
                        |> (static fn(string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR));
                    self::assertEcho($authority, $route, $response);
                    printf("PASS: template: %s preserves HTTPS authority %s and replaces spoofed origin headers\n", $route, $authority);
                }
            }
            self::shutdown($drill, $workspace);
            printf("Proxy log qualification completed in %.1f seconds.\n", hrtime(true) / 1e9 - $started);
            return 0;
        } finally {
            $drill->cleaning = true;
            clearstatcache(true, $workspace . '/nginx.pid');
            if (file_exists($workspace . '/nginx.pid')) {
                try {
                    self::shutdown($drill, $workspace);
                } catch (Throwable $error) {
                    fwrite(STDERR, 'nginx cleanup failed: ' . $error->getMessage() . "\n");
                }
            }
            if ($echo !== null) {
                $drill->stop($echo);
            }
            $drill->close();
        }
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        return match ($arguments[0] ?? '') {
            '--inside' => self::inside($root, $arguments[1] ?? throw new \InvalidArgumentException('Missing proxy workspace')),
            '--echo' => self::echo($arguments),
            default => self::host($root),
        };
    }

    /** @param list<string> $arguments */
    private static function echo(array $arguments): int
    {
        EchoServer::run(
            $arguments[1] ?? throw new \InvalidArgumentException('Missing echo readiness path'),
            (int) ($arguments[2] ?? 9501),
        );
        return 0;
    }
}
