<?php

declare(strict_types=1);

namespace App\Tests\Integration\Docker;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Validates both nginx configs: the dev template (envsubst) and the
 * production static config (HTTP-only, behind external reverse proxy).
 */
final class NginxTemplateTest extends TestCase
{
    private const DEV_TEMPLATE_PATH = __DIR__ . '/../../../docker/dev/nginx.conf.template';
    private const PROD_CONFIG_PATH = __DIR__ . '/../../../docker/prod/nginx.conf';

    /**
     * Template must exist at the expected path.
     */
    public function testTemplateFileExists(): void
    {
        $this->assertFileExists(self::DEV_TEMPLATE_PATH);
    }

    /**
     * Template must contain exactly the ${SERVER_NAME} placeholder in both server blocks.
     */
    public function testTemplateContainsServerNamePlaceholder(): void
    {
        $template = $this->readDevTemplate();
        preg_match_all('/\$\{SERVER_NAME\}/', $template, $matches);

        $this->assertCount(2, $matches[0], 'Template must contain exactly 2 ${SERVER_NAME} placeholders (HTTP + HTTPS server blocks).');
    }

    /**
     * Template must NOT contain hardcoded server_name values.
     */
    public function testTemplateHasNoHardcodedServerName(): void
    {
        $template = $this->readDevTemplate();

        preg_match_all('/server_name\s+(.+);/', $template, $matches);

        foreach ($matches[1] as $value) {
            $this->assertSame('${SERVER_NAME}', trim($value), "server_name must use \${SERVER_NAME} placeholder, found: {$value}");
        }
    }

    /**
     * Substitution with a valid domain produces a config with no remaining placeholders.
     */
    public function testSubstitutionReplacesAllPlaceholders(): void
    {
        $domain = 'myapp.baander.app';
        $config = $this->substitute($domain);

        $this->assertStringNotContainsString('${SERVER_NAME}', $config);

        preg_match_all('/server_name\s+([^;]+);/', $config, $matches);
        foreach ($matches[1] as $value) {
            $this->assertSame($domain, trim($value));
        }
    }

    /**
     * Substitution with domain containing dots and hyphens works correctly.
     */
    public function testSubstitutionWithComplexDomain(): void
    {
        $domain = 'my-app.staging.baander.app';
        $config = $this->substitute($domain);

        $this->assertStringNotContainsString('${SERVER_NAME}', $config);
        $this->assertStringContainsString("server_name {$domain};", $config);
    }

    /**
     * Substitution with the literal loopback address works (dev scenario).
     */
    public function testSubstitutionWithLocalhost(): void
    {
        $config = $this->substitute('127.0.0.1');

        $this->assertStringNotContainsString('${SERVER_NAME}', $config);
        $this->assertStringContainsString('server_name 127.0.0.1;', $config);
    }

    /**
     * Only the SERVER_NAME variable is templated — no other ${...} placeholders exist.
     */
    public function testNoOtherTemplateVariablesExist(): void
    {
        $template = $this->readDevTemplate();

        preg_match_all('/\$\{[^}]+\}/', $template, $matches);

        $placeholders = array_unique($matches[0]);
        $this->assertSame(['${SERVER_NAME}'], $placeholders, 'Only ${SERVER_NAME} should be a template placeholder.');
    }

    /**
     * The substituted config contains all required location blocks.
     */
    public function testSubstitutedConfigContainsAllLocationBlocks(): void
    {
        $config = $this->substitute('baander.app');

        $expectedLocations = [
            'location ~ ^/api/stream/.+\.m4s$',
            'location ~ ^/api/stream/.+/init\.mp4$',
            'location ~ ^/api/stream/.+\.(m3u8|mpd)$',
            'location = /api/stream/track',
            'location ~ ^/api/images/[^/]+/file$',
            'location /',
        ];

        foreach ($expectedLocations as $location) {
            $this->assertStringContainsString($location, $config, "Config must contain location block: {$location}");
        }
    }

    /**
     * The substituted config has both HTTP (redirect) and HTTPS server blocks.
     */
    public function testSubstitutedConfigHasBothServerBlocks(): void
    {
        $config = $this->substitute('baander.app');

        preg_match_all('/^server\s*\{/m', $config, $matches);
        $this->assertCount(2, $matches[0], 'Config must have exactly 2 server blocks (HTTP redirect + HTTPS).');
    }

    /**
     * The HTTPS server block has required SSL directives.
     */
    public function testHttpsServerBlockHasSslDirectives(): void
    {
        $config = $this->substitute('baander.app');

        $this->assertStringContainsString('listen 443 ssl;', $config);
        $this->assertStringContainsString('ssl_certificate', $config);
        $this->assertStringContainsString('ssl_certificate_key', $config);
    }

    /**
     * The config uses keepalive on the upstream for Swoole connection reuse.
     */
    public function testUpstreamHasKeepalive(): void
    {
        $config = $this->substitute('baander.app');

        $this->assertStringContainsString('keepalive 64;', $config);
    }

    /**
     * The config does not contain the trailing-slash $uri/ pattern.
     */
    public function testNoTrailingSlashInTryFiles(): void
    {
        $config = $this->substitute('baander.app');

        $this->assertStringNotContainsString('$uri/', $config);
    }

    /**
     * The config allows uploads up to 100MB.
     */
    public function testClientMaxBodySize100m(): void
    {
        $config = $this->substitute('baander.app');

        $this->assertStringContainsString('client_max_body_size 100m;', $config);
    }

    /**
     * The config uses limit_except instead of the if anti-pattern.
     */
    public function testUsesLimitExceptNotIf(): void
    {
        $config = $this->substitute('baander.app');

        $this->assertStringContainsString('limit_except GET POST HEAD OPTIONS PUT DELETE PATCH', $config);
        $this->assertStringNotContainsString('if ($request_method', $config);
    }

    /**
     * Gzip is enabled for text-based content types.
     */
    public function testGzipIsEnabled(): void
    {
        $config = $this->substitute('baander.app');

        $this->assertStringContainsString('gzip on;', $config);
        $this->assertStringContainsString('gzip_vary on;', $config);
        $this->assertStringContainsString('gzip_types', $config);

        $gzipTypes = [
            'application/json',
            'application/vnd.apple.mpegurl',
            'application/dash+xml',
            'application/javascript',
        ];
        foreach ($gzipTypes as $type) {
            $this->assertStringContainsString($type, $config, "Gzip must compress {$type}");
        }
    }

    /**
     * SSL is restricted to TLS 1.2 and 1.3 only.
     */
    public function testSslProtocolsModern(): void
    {
        $config = $this->substitute('baander.app');

        $this->assertStringContainsString('ssl_protocols TLSv1.2 TLSv1.3;', $config);
        $this->assertStringNotContainsString('TLSv1;', $config);
        $this->assertStringNotContainsString('SSLv', $config);
    }

    /**
     * SSL session caching is configured for handshake reuse.
     */
    public function testSslSessionCache(): void
    {
        $config = $this->substitute('baander.app');

        $this->assertStringContainsString('ssl_session_cache shared:SSL:10m;', $config);
        $this->assertStringContainsString('ssl_session_timeout 1d;', $config);
    }

    /**
     * Security headers are present.
     */
    public function testSecurityHeaders(): void
    {
        $config = $this->substitute('baander.app');

        $this->assertStringContainsString('X-Content-Type-Options nosniff', $config);
        $this->assertStringContainsString('Strict-Transport-Security', $config);
        $this->assertStringContainsString('max-age=63072000', $config);
    }

    /**
     * Validate the substituted development config with a real nginx process.
     */
    public function testNginxSyntaxValidation(): void
    {
        $this->assertNginxConfigValid($this->substitute('syntax.baander.app'));
    }

    public function testProdNginxSyntaxValidation(): void
    {
        $this->assertNginxConfigValid($this->readProdConfig());
    }

    /**
     * Prometheus scrapes web on the application network, so this gateway answers /metrics
     * itself and still forwards every other path to web.
     */
    public function testProdConfigAnswersMetricsItselfAndForwardsOtherPaths(): void
    {
        $listener = $this->unusedPort();
        $upstream = $this->unusedPort();
        $local = [
            'listen 80;' => 'listen 127.0.0.1:' . $listener . ';',
            'server app:9501;' => 'server 127.0.0.1:' . $upstream . ';',
        ];

        $this->inNginxPrefix($this->readProdConfig(), $local, function (string $nginx, string $directory) use ($listener): void {
            exec($nginx . ' 2>&1', $output, $exitCode);
            $this->assertSame(0, $exitCode, "nginx did not start:\n" . implode("\n", $output));
            try {
                // Nothing listens on the upstream port, so a forwarded request answers 502.
                $this->assertSame(
                    ['/metrics' => 404, '/health' => 502],
                    ['/metrics' => $this->statusOf($listener, '/metrics'), '/health' => $this->statusOf($listener, '/health')],
                );
            } finally {
                exec($nginx . ' -s stop 2>&1');
                for ($attempt = 0; $attempt < 50 && is_file($directory . '/nginx.pid'); ++$attempt) {
                    usleep(100_000);
                }
            }
        });
    }

    private function assertNginxConfigValid(string $config): void
    {
        $this->inNginxPrefix($config, [], function (string $nginx): void {
            exec($nginx . ' -t 2>&1', $output, $exitCode);
            $this->assertSame(0, $exitCode, "nginx -t failed:\n" . implode("\n", $output));
        });
    }

    /**
     * Writes an http-context fragment into a private nginx prefix and calls $use with the nginx
     * command line for it. Deployment dependencies are swapped for local ones so nginx can load
     * the fragment without DNS or shared paths; $local swaps further values first.
     *
     * @param array<string, string> $local
     * @param callable(string, string): void $use receives the command line and the prefix directory
     */
    private function inNginxPrefix(string $config, array $local, callable $use): void
    {
        $nginxPath = $this->findNginxBinary();
        $this->assertNotNull($nginxPath, 'nginx must be installed to validate configuration syntax.');

        $directory = sys_get_temp_dir() . '/baander-nginx-' . (random_bytes(8) |> bin2hex(...));
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory, 0700);

        try {
            if (str_contains($config, 'ssl_certificate ')) {
                $this->writeTestCertificate($directory);
            }

            $config = strtr($config, $local + [
                'server app:9501;' => 'server 127.0.0.1:9501;',
                '/etc/nginx/certificates/cert.pem' => $directory . '/cert.pem',
                '/etc/nginx/certificates/key.pem' => $directory . '/key.pem',
                '/var/log/nginx/access.log' => $directory . '/access.log',
                '/var/log/nginx/error.log' => $directory . '/error.log',
            ]);

            $wrapper = "pid {$directory}/nginx.pid;\n"
                . "error_log {$directory}/error.log;\n"
                . "events {}\nhttp {\n"
                . "client_body_temp_path {$directory}/client-body;\n"
                . "proxy_temp_path {$directory}/proxy;\n"
                . "fastcgi_temp_path {$directory}/fastcgi;\n"
                . "uwsgi_temp_path {$directory}/uwsgi;\n"
                . "scgi_temp_path {$directory}/scgi;\n"
                . $config . "\n}\n";
            $configPath = $directory . '/nginx.conf';
            $this->assertNotFalse(file_put_contents($configPath, $wrapper));

            $use(
                escapeshellarg($nginxPath) . ' -p ' . escapeshellarg($directory . '/')
                . ' -e ' . escapeshellarg($directory . '/error.log')
                . ' -c ' . escapeshellarg($configPath),
                $directory,
            );
        } finally {
            $filesystem->remove($directory);
        }
    }

    private function unusedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    private function statusOf(int $port, string $path): ?int
    {
        $curl = curl_init('http://127.0.0.1:' . $port . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);

        return curl_exec($curl) === false ? null : curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    }

    private function writeTestCertificate(string $directory): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key, 'Could not generate the nginx test private key.');
        $request = openssl_csr_new(['commonName' => 'syntax.baander.app'], $key);
        $this->assertNotFalse($request, 'Could not generate the nginx test certificate request.');
        $certificate = openssl_csr_sign($request, null, $key, 1);
        $this->assertNotFalse($certificate, 'Could not sign the nginx test certificate.');
        $this->assertTrue(openssl_pkey_export_to_file($key, $directory . '/key.pem'));
        $this->assertTrue(openssl_x509_export_to_file($certificate, $directory . '/cert.pem'));
    }

    // =========================================================================
    // Production config tests
    // =========================================================================

    /**
     * Production config file must exist.
     */
    public function testProdConfigFileExists(): void
    {
        $this->assertFileExists(self::PROD_CONFIG_PATH);
    }

    /**
     * Production config is a plain static config with no template placeholders.
     */
    public function testProdConfigHasNoTemplatePlaceholders(): void
    {
        $config = $this->readProdConfig();

        $this->assertStringNotContainsString('${', $config, 'Prod config must not contain any template placeholders.');
    }

    /**
     * Production config listens on HTTP port 80 only (no HTTPS).
     */
    public function testProdConfigHttpOnly(): void
    {
        $config = $this->readProdConfig();

        $this->assertStringContainsString('listen 80;', $config);
        $this->assertStringNotContainsString('listen 443', $config);
        $this->assertStringNotContainsString('ssl_certificate', $config);
    }

    /**
     * Production config has exactly one server block.
     */
    public function testProdConfigSingleServerBlock(): void
    {
        $config = $this->readProdConfig();

        preg_match_all('/^server\s*\{/m', $config, $matches);
        $this->assertCount(1, $matches[0], 'Prod config must have exactly 1 server block (HTTP only).');
    }

    /**
     * Production config trusts X-Forwarded-For from upstream proxy.
     */
    public function testProdConfigTrustsProxy(): void
    {
        $config = $this->readProdConfig();

        $this->assertStringContainsString('real_ip_header X-Forwarded-For;', $config);
        $this->assertStringContainsString('real_ip_recursive on;', $config);
    }

    /**
     * Behind the external TLS proxy this server listens on plain HTTP, so it passes on the
     * scheme the proxy reported and falls back to its own only when none was sent.
     */
    public function testProdConfigForwardsTheUpstreamScheme(): void
    {
        $config = $this->readProdConfig();

        $this->assertMatchesRegularExpression('/map \$http_x_forwarded_proto \$forwarded_proto \{\s*default\s+\$http_x_forwarded_proto;\s*\'\'\s+\$scheme;\s*\}/', $config);
        $this->assertStringNotContainsString('X-Forwarded-Proto $scheme', $config);
        $this->assertStringContainsString('proxy_set_header X-Forwarded-Proto $forwarded_proto;', $config);
    }

    /**
     * A location that sets any proxy header inherits none from the server block, so each
     * such location repeats the forwarding headers.
     */
    public function testProdLocationsWithTheirOwnProxyHeadersStillForwardTheClientRequest(): void
    {
        preg_match_all('/^    location [^{]+\{(?:[^{}]|\{[^{}]*\})*\}/m', $this->readProdConfig(), $locations);
        $withOwnHeaders = array_filter($locations[0], static fn (string $block): bool => str_contains($block, 'proxy_set_header'));

        $this->assertNotEmpty($withOwnHeaders);
        foreach ($withOwnHeaders as $block) {
            foreach (['Host $host', 'X-Forwarded-Host $host', 'X-Forwarded-For $proxy_add_x_forwarded_for', 'X-Forwarded-Proto $forwarded_proto', 'X-Real-IP $remote_addr'] as $header) {
                $this->assertStringContainsString('proxy_set_header ' . $header . ';', $block, strtok($block, "\n"));
            }
        }
    }

    /**
     * Production config has all the same streaming location blocks as dev.
     */
    public function testProdConfigHasStreamingLocations(): void
    {
        $config = $this->readProdConfig();

        $expectedLocations = [
            'location ~ ^/api/stream/.+\.m4s$',
            'location ~ ^/api/stream/.+/init\.mp4$',
            'location ~ ^/api/stream/.+\.(m3u8|mpd)$',
            'location ~ ^/api/stream/(track|media)$',
            'location ~ ^/api/images/[^/]+/file$',
            'location /',
        ];

        foreach ($expectedLocations as $location) {
            $this->assertStringContainsString($location, $config, "Prod config must contain location block: {$location}");
        }
    }

    /**
     * Production config has the same upload limit, gzip, and keepalive as dev.
     */
    public function testProdConfigHasUploadLimit(): void
    {
        $config = $this->readProdConfig();

        $this->assertStringContainsString('client_max_body_size 100m;', $config);
        $this->assertStringContainsString('keepalive 64;', $config);
        $this->assertStringContainsString('gzip on;', $config);
    }

    /**
     * Production config uses server_name _ (catch-all, no hardcoded domain).
     */
    public function testProdConfigServerNameCatchAll(): void
    {
        $config = $this->readProdConfig();

        $this->assertStringContainsString('server_name _;', $config);
    }

    /**
     * Production config does not have SSL-specific directives (no HSTS, no ssl_protocols).
     */
    public function testProdConfigHasNoSslDirectives(): void
    {
        $config = $this->readProdConfig();

        $this->assertStringNotContainsString('ssl_protocols', $config);
        $this->assertStringNotContainsString('ssl_session_cache', $config);
        $this->assertStringNotContainsString('Strict-Transport-Security', $config);
    }

    // =========================================================================
    // Dev template helpers
    // =========================================================================

    private function readDevTemplate(): string
    {
        $this->assertFileExists(self::DEV_TEMPLATE_PATH);
        $content = file_get_contents(self::DEV_TEMPLATE_PATH);
        $this->assertNotFalse($content);

        return $content;
    }

    private function substitute(string $serverName): string
    {
        return str_replace('${SERVER_NAME}', $serverName, $this->readDevTemplate());
    }

    // =========================================================================
    // Production config helpers
    // =========================================================================

    private function readProdConfig(): string
    {
        $this->assertFileExists(self::PROD_CONFIG_PATH);
        $content = file_get_contents(self::PROD_CONFIG_PATH);
        $this->assertNotFalse($content);

        return $content;
    }

    private function findNginxBinary(): ?string
    {
        $directories = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
        $directories[] = '/usr/sbin';
        $directories[] = '/usr/local/sbin';

        foreach (array_unique($directories) as $directory) {
            $path = $directory . '/nginx';
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /** @return iterable<string, array{string}> */
    public static function nginxConfigurations(): iterable
    {
        yield 'production' => [self::PROD_CONFIG_PATH];
        yield 'development' => [self::DEV_TEMPLATE_PATH];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nginxConfigurations')]
    public function testEveryAccessLogUsesAFormatWithoutTheQueryStringOrReferrer(string $path): void
    {
        $config = (string) file_get_contents($path);
        preg_match_all('/^\s*access_log\s+\S+\s*(\S*?);/m', $config, $logs);
        self::assertNotSame([], $logs[1]);
        foreach ($logs[1] as $format) {
            self::assertNotSame('', $format, 'An access_log without a format uses combined, which logs $request.');
            $definition = $this->logFormat($config, $format);
            self::assertStringContainsString('$uri', $definition);
            foreach (['$request ', '$request"', '$request_uri', '$args', '$query_string', '$is_args', '$http_referer'] as $field) {
                self::assertStringNotContainsString($field, $definition, $format);
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nginxConfigurations')]
    public function testAnAccessLogLineForASignedDeliveryUrlHasNoQueryString(string $path): void
    {
        $directory = sys_get_temp_dir() . '/baander-nginx-log-' . (random_bytes(6) |> bin2hex(...));
        mkdir($directory, 0700);
        $config = (string) file_get_contents($path);
        file_put_contents($directory . '/nginx.conf', implode("\n", [
            'daemon off;', 'master_process off;', 'pid ' . $directory . '/nginx.pid;', 'error_log ' . $directory . '/error.log;',
            'events { worker_connections 8; }',
            'http {',
            $this->logFormat($config, 'path') . ';',
            'client_body_temp_path ' . $directory . '/body; proxy_temp_path ' . $directory . '/proxy;',
            'fastcgi_temp_path ' . $directory . '/fastcgi; uwsgi_temp_path ' . $directory . '/uwsgi; scgi_temp_path ' . $directory . '/scgi;',
            'access_log ' . $directory . '/access.log path;',
            'server { listen unix:' . $directory . '/nginx.sock; location / { return 204; } }',
            '}',
        ]));
        $nginx = new \Symfony\Component\Process\Process(['nginx', '-c', $directory . '/nginx.conf', '-p', $directory]);
        $nginx->start();
        try {
            for ($attempt = 0; $attempt < 100 && !file_exists($directory . '/nginx.sock'); ++$attempt) {
                usleep(20_000);
            }
            $client = stream_socket_client('unix://' . $directory . '/nginx.sock', $errno, $error, 5);
            self::assertIsResource($client, $nginx->getErrorOutput());
            fwrite($client, "GET /api/stream/original/track?id=a1&exp=1792065607&sig=e27974ef93f1f0dc HTTP/1.0\r\nHost: baander.app\r\nReferer: https://baander.app/play?sig=e27974ef93f1f0dc\r\n\r\n");
            self::assertStringStartsWith('HTTP/1.1 204', (string) fgets($client));
            fclose($client);
        } finally {
            $nginx->stop(1);
        }
        $line = (string) file_get_contents($directory . '/access.log');
        new Filesystem()->remove($directory);

        self::assertStringContainsString('"GET /api/stream/original/track HTTP/1.0" 204', $line);
        self::assertStringNotContainsString('sig=', $line);
        self::assertStringNotContainsString('exp=', $line);
    }

    /** The definition of a named log_format, which may continue over several quoted lines. */
    private function logFormat(string $config, string $name): string
    {
        self::assertSame(1, preg_match('/^log_format\s+' . preg_quote($name, '/') . '\s+[^;]+/m', $config, $match), 'log_format ' . $name);

        return $match[0];
    }
}
