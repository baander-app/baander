<?php

declare(strict_types=1);

namespace Baander\Tooling\Dependencies;

use Baander\Tooling\Process\CommandExecutor;
use Baander\Tooling\Process\CompletionPolicy;
use Baander\Tooling\Process\ProcessSpec;

final readonly class UpstreamEvidence
{
    private const int MAX_BYTES = 8_388_608;

    /** @param array<string,string> $environment
     */
    public function __construct(
        private CommandExecutor $executor,
        private float $timeout,
        private array $environment = [],
    ) {
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('Upstream request timeout must be positive and finite.');
        }
    }

    public function fetch(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || !in_array($parts['host'] ?? '', ['repo.packagist.org', 'www.php.net', 'pecl.php.net', 'getcomposer.org'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Unsupported upstream HTTPS source.');
        }
        $environment = array_intersect_key($this->environment, array_flip([
            'PATH', 'SSL_CERT_FILE', 'SSL_CERT_DIR', 'CURL_CA_BUNDLE',
            'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'NO_PROXY',
            'http_proxy', 'https_proxy', 'all_proxy', 'no_proxy',
        ]));
        $result = $this->executor->run(new ProcessSpec(
            ['curl', '--disable', '--silent', '--show-error', '--location', '--include',
                '--proto', '=https', '--proto-redir', '=https', '--max-redirs', '3',
                '--connect-timeout', (string) min(15.0, $this->timeout), '--max-time', (string) $this->timeout,
                '--max-filesize', (string) self::MAX_BYTES, '--write-out', "\n%{http_code}", '--url', $url],
            environment: $environment,
            timeout: $this->timeout,
            processGroup: true,
            replaceEnvironment: true,
        ), new CompletionPolicy());
        if ($result->stderr !== '') {
            throw new \RuntimeException('Upstream request emitted a diagnostic; fresh evidence is unavailable.');
        }
        if (strlen($result->stdout) > self::MAX_BYTES) {
            throw new \RuntimeException('Upstream response exceeds the supported size.');
        }
        if (preg_match('/\n([0-9]{3})\z/', $result->stdout, $status, PREG_OFFSET_CAPTURE) !== 1) {
            throw new \RuntimeException('Upstream response has no HTTP status.');
        }
        $code = (int) $status[1][0];
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('Upstream HTTP status ' . $code . '.');
        }
        $body = substr($result->stdout, 0, $status[0][1]);
        while (str_starts_with($body, 'HTTP/')) {
            if (preg_match('/\AHTTP\/[^\r\n]+\r?\n(.*?)\r?\n\r?\n/s', $body, $headers) !== 1) {
                throw new \RuntimeException('Malformed upstream HTTP headers.');
            }
            if (preg_match('/^Warning:\s*11[01]\b/im', $headers[1]) === 1) {
                throw new \RuntimeException('Upstream response reports stale cache evidence.');
            }
            $body = substr($body, strlen($headers[0]));
        }
        if ($body === '') {
            throw new \RuntimeException('Upstream response is empty.');
        }
        return $body;
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array{version:string,reference:?string,php:?string}>
     */
    public static function releases(array $payload, string $package): array
    {
        if (isset($payload['minified']) && $payload['minified'] !== 'composer/2.0') {
            throw new \RuntimeException('Unsupported Packagist metadata minification.');
        }
        if (!is_array($payload['packages'] ?? null) || !array_key_exists($package, $payload['packages'])
            || !is_array($payload['packages'][$package]) || !array_is_list($payload['packages'][$package])) {
            throw new \RuntimeException('Packagist metadata has no release list for the requested package.');
        }
        $previous = [];
        $releases = [];
        foreach ($payload['packages'][$package] as $record) {
            if (!is_array($record) || array_is_list($record)) {
                throw new \RuntimeException('Malformed Packagist release record.');
            }
            if (isset($payload['minified'])) {
                $record = array_replace($previous, $record);
                foreach ($record as $field => $value) {
                    if ($value === '__unset') {
                        unset($record[$field]);
                    }
                }
                $previous = $record;
            }
            if (($record['name'] ?? null) !== $package || !is_string($record['version'] ?? null)) {
                throw new \RuntimeException('Packagist release identity is missing or differs from the requested package.');
            }
            $version = self::stableVersion($record['version']);
            if ($version === null) {
                continue;
            }
            if ((isset($record['source']) && !is_array($record['source']))
                || (isset($record['require']) && !is_array($record['require']))) {
                throw new \RuntimeException('Malformed Packagist source or requirement metadata.');
            }
            $reference = $record['source']['reference'] ?? null;
            $php = $record['require']['php'] ?? null;
            if (($reference !== null && !is_string($reference)) || ($php !== null && !is_string($php))) {
                throw new \RuntimeException('Malformed Packagist source reference or PHP constraint.');
            }
            $release = ['version' => $version, 'reference' => $reference, 'php' => $php];
            if (isset($releases[$version]) && $releases[$version] !== $release) {
                throw new \RuntimeException('Packagist contains ambiguous stable release metadata.');
            }
            $releases[$version] = $release;
        }
        $releases = array_values($releases);
        usort($releases, static fn(array $left, array $right): int => version_compare($right['version'], $left['version']));
        return $releases;
    }

    /** @param array<string,mixed>|string $payload
     */
    public static function runtimeVersion(string $kind, array|string $payload): string
    {
        if ($kind === 'swoole') {
            if (!is_string($payload)) {
                throw new \RuntimeException('PECL release evidence must be XML.');
            }
            return self::peclVersion($payload);
        }
        if (is_string($payload)) {
            $payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        }
        if (!is_array($payload)) {
            throw new \RuntimeException('Runtime release evidence must be a JSON object.');
        }
        $versions = [];
        if ($kind === 'php') {
            if (isset($payload['version'])) {
                $records = [$payload];
            } else {
                $records = array_values($payload);
            }
            foreach ($records as $record) {
                if (!is_array($record) || !is_string($record['version'] ?? null)) {
                    throw new \RuntimeException('Malformed PHP release metadata.');
                }
                $version = self::stableVersion($record['version']);
                if ($version !== null) {
                    $versions[] = $version;
                }
            }
        } elseif ($kind === 'composer') {
            if (!is_array($payload['stable'] ?? null) || !array_is_list($payload['stable'])) {
                throw new \RuntimeException('Composer stable channel is missing.');
            }
            foreach ($payload['stable'] as $record) {
                if (!is_array($record) || !is_string($record['version'] ?? null)) {
                    throw new \RuntimeException('Malformed Composer stable release.');
                }
                $version = self::stableVersion($record['version']);
                if ($version === null) {
                    throw new \RuntimeException('Composer stable channel contains a nonstable release.');
                }
                $versions[] = $version;
            }
        } else {
            throw new \InvalidArgumentException('Unsupported runtime release source.');
        }
        return self::newest($versions);
    }

    private static function stableVersion(string $version): ?string
    {
        if (preg_match('/\Av?([0-9]+(?:\.[0-9]+){1,3})(?:-stable)?\z/i', $version, $match) === 1) {
            return $match[1];
        }
        if (preg_match('/\A(?:dev-[A-Za-z0-9_.\/-]+|v?[0-9]+(?:\.[0-9x*]+){0,3}(?:[.-]?(?:alpha|beta|rc|patch|pl|a|b)[.-]?[0-9]*|[.-]dev)(?:\+[^\s]+)?)\z/i', $version) === 1) {
            return null;
        }
        throw new \RuntimeException('Unsupported upstream release version syntax.');
    }

    private static function peclVersion(string $payload): string
    {
        $attributes = '(?:\\s+[A-Za-z_][A-Za-z0-9_.:-]*\\s*=\\s*(?:"[^"<>&]*"|\'[^\'<>&]*\'))*';
        if (str_contains($payload, '<!') || str_contains($payload, '&')
            || preg_match('/\A\s*(?:<\?xml' . $attributes . '\s*\?>\s*)?<a' . $attributes . '>\s*(?:<p>[^<>]+<\/p>\s*)?(?:<c>[^<>]+<\/c>\s*)?((?:<r>\s*<v>[^<>]+<\/v>\s*<s>[^<>]+<\/s>\s*<\/r>\s*)+)<\/a>\s*\z/s', $payload, $outer) !== 1) {
            throw new \RuntimeException('Unsupported or malformed PECL release XML.');
        }
        preg_match_all('/<r>\s*<v>([^<>]+)<\/v>\s*<s>([^<>]+)<\/s>\s*<\/r>/', $outer[1], $records, PREG_SET_ORDER);
        $versions = [];
        foreach ($records as $record) {
            if (!in_array($record[2], ['stable', 'beta', 'alpha', 'devel', 'snapshot'], true)) {
                throw new \RuntimeException('Unsupported PECL release stability.');
            }
            $version = self::stableVersion($record[1]);
            if ($record[2] === 'stable') {
                if ($version === null) {
                    throw new \RuntimeException('PECL stable channel contains a nonstable version.');
                }
                $versions[] = $version;
            }
        }
        return self::newest($versions);
    }

    /** @param list<string> $versions
     */
    private static function newest(array $versions): string
    {
        if ($versions === []) {
            throw new \RuntimeException('Upstream source has no stable releases.');
        }
        usort($versions, static fn(string $left, string $right): int => version_compare($right, $left));
        return $versions[0];
    }
}
