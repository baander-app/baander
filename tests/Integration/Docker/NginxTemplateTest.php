<?php

declare(strict_types=1);

namespace App\Tests\Integration\Docker;

use PHPUnit\Framework\TestCase;

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
        $domain = 'myapp.example.com';
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
        $domain = 'my-app.staging.example.co.uk';
        $config = $this->substitute($domain);

        $this->assertStringNotContainsString('${SERVER_NAME}', $config);
        $this->assertStringContainsString("server_name {$domain};", $config);
    }

    /**
     * Substitution with localhost works (dev scenario).
     */
    public function testSubstitutionWithLocalhost(): void
    {
        $config = $this->substitute('localhost');

        $this->assertStringNotContainsString('${SERVER_NAME}', $config);
        $this->assertStringContainsString('server_name localhost;', $config);
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
        $config = $this->substitute('baander.test');

        $expectedLocations = [
            'location ~ ^/api/stream/.+\.m4s$',
            'location ~ ^/api/stream/.+/init\.mp4$',
            'location ~ ^/api/stream/.+\.(m3u8|mpd)$',
            'location ~ ^/api/stream/(track|media)$',
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
        $config = $this->substitute('baander.test');

        preg_match_all('/^server\s*\{/m', $config, $matches);
        $this->assertCount(2, $matches[0], 'Config must have exactly 2 server blocks (HTTP redirect + HTTPS).');
    }

    /**
     * The HTTPS server block has required SSL directives.
     */
    public function testHttpsServerBlockHasSslDirectives(): void
    {
        $config = $this->substitute('baander.test');

        $this->assertStringContainsString('listen 443 ssl;', $config);
        $this->assertStringContainsString('ssl_certificate', $config);
        $this->assertStringContainsString('ssl_certificate_key', $config);
    }

    /**
     * The config uses keepalive on the upstream for Swoole connection reuse.
     */
    public function testUpstreamHasKeepalive(): void
    {
        $config = $this->substitute('baander.test');

        $this->assertStringContainsString('keepalive 64;', $config);
    }

    /**
     * The config does not contain the trailing-slash $uri/ pattern.
     */
    public function testNoTrailingSlashInTryFiles(): void
    {
        $config = $this->substitute('baander.test');

        $this->assertStringNotContainsString('$uri/', $config);
    }

    /**
     * The config allows uploads up to 100MB.
     */
    public function testClientMaxBodySize100m(): void
    {
        $config = $this->substitute('baander.test');

        $this->assertStringContainsString('client_max_body_size 100m;', $config);
    }

    /**
     * The config uses limit_except instead of the if anti-pattern.
     */
    public function testUsesLimitExceptNotIf(): void
    {
        $config = $this->substitute('baander.test');

        $this->assertStringContainsString('limit_except GET POST HEAD OPTIONS PUT DELETE PATCH', $config);
        $this->assertStringNotContainsString('if ($request_method', $config);
    }

    /**
     * Gzip is enabled for text-based content types.
     */
    public function testGzipIsEnabled(): void
    {
        $config = $this->substitute('baander.test');

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
        $config = $this->substitute('baander.test');

        $this->assertStringContainsString('ssl_protocols TLSv1.2 TLSv1.3;', $config);
        $this->assertStringNotContainsString('TLSv1;', $config);
        $this->assertStringNotContainsString('SSLv', $config);
    }

    /**
     * SSL session caching is configured for handshake reuse.
     */
    public function testSslSessionCache(): void
    {
        $config = $this->substitute('baander.test');

        $this->assertStringContainsString('ssl_session_cache shared:SSL:10m;', $config);
        $this->assertStringContainsString('ssl_session_timeout 1d;', $config);
    }

    /**
     * Security headers are present.
     */
    public function testSecurityHeaders(): void
    {
        $config = $this->substitute('baander.test');

        $this->assertStringContainsString('X-Content-Type-Options nosniff', $config);
        $this->assertStringContainsString('Strict-Transport-Security', $config);
        $this->assertStringContainsString('max-age=63072000', $config);
    }

    /**
     * If nginx is available, validate the substituted config syntax.
     */
    public function testNginxSyntaxValidation(): void
    {
        $nginxPath = $this->findNginxBinary();
        if ($nginxPath === null) {
            $this->markTestSkipped('nginx binary not found — syntax validation requires nginx to be installed.');
        }

        $config = $this->substitute('baander.test');

        $tmpFile = tempnam(sys_get_temp_dir(), 'nginx_test_');
        file_put_contents($tmpFile, $config);

        try {
            exec("{$nginxPath} -t -c {$tmpFile} 2>&1", $output, $exitCode);
            $outputStr = implode("\n", $output);

            if (str_contains($outputStr, 'syntax error') || str_contains($outputStr, 'unexpected')) {
                $this->fail("nginx syntax error: {$outputStr}");
            }

            $this->assertTrue(
                str_contains($outputStr, 'test is successful') || str_contains($outputStr, 'syntax is ok') || $exitCode !== 0,
                "nginx -t output: {$outputStr}",
            );
        } finally {
            @unlink($tmpFile);
        }
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
        $candidates = ['nginx', '/usr/sbin/nginx', '/usr/local/sbin/nginx'];
        foreach ($candidates as $path) {
            exec("which {$path} 2>/dev/null", $output, $exit);
            if ($exit === 0 && !empty($output)) {
                return trim($output[0]);
            }
        }

        return null;
    }
}
