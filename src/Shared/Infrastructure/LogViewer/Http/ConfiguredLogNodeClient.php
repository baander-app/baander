<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer\Http;

use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\LogViewer\Service\LogNodeClientInterface;
use App\Shared\Infrastructure\Swoole\Async;
use Swoole\Runtime;

final class ConfiguredLogNodeClient implements LogNodeClientInterface
{
    /** @var array<string, array{url:string,tokenFile:string}> */
    private array $configuration = [];

    public function __construct(string $nodesJson)
    {
        try {
            $nodes = json_decode($nodesJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidInputException('Invalid log node configuration.', previous: $e);
        }
        if (!is_array($nodes) || !array_is_list($nodes) || count($nodes) > 32) {
            throw new InvalidInputException('Log nodes must be a list of at most 32 entries.');
        }
        foreach ($nodes as $node) {
            if (!is_array($node) || !is_string($node['id'] ?? null) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $node['id']) !== 1 || !is_string($node['url'] ?? null) || !is_string($node['tokenFile'] ?? null)) {
                throw new InvalidInputException('Invalid configured log node.');
            }
            $url = parse_url($node['url']);
            $scheme = $url['scheme'] ?? '';
            $host = $url['host'] ?? '';
            $local = in_array($host, ['localhost', '127.0.0.1', '::1', 'log-agent'], true);
            if (!in_array($scheme, ['https', 'http'], true) || $host === '' || ($scheme === 'http' && !$local) || (isset($url['user']) || isset($url['pass'])) || isset($url['query']) || isset($url['fragment']) || ($url['path'] ?? '') !== '' || isset($this->configuration[$node['id']])) {
                throw new InvalidInputException('Log nodes require unique IDs and HTTPS URLs, except trusted local agents.');
            }
            $this->configuration[$node['id']] = ['url' => $node['url'], 'tokenFile' => $node['tokenFile']];
        }
    }

    public function nodes(): array
    {
        return array_keys($this->configuration);
    }

    public function get(string $node, string $path, array $parameters = []): array
    {
        $configuration = $this->configuration[$node] ?? throw new InvalidInputException('Unknown log node.');
        if (!in_array($path, ['/health', '/sources', '/entries'], true) && preg_match('~^/entry/[A-Za-z0-9_%.-]+$~D', $path) !== 1) {
            throw new InvalidInputException('Unknown log agent operation.');
        }
        $token = @file_get_contents($configuration['tokenFile']);
        if ($token === false || trim($token) === '' || strlen($token) > 4096 || preg_match('/[\r\n]/', trim($token)) === 1) {
            throw new ServiceUnavailableException('Log node credential is unavailable.');
        }
        $target = $configuration['url'].$path;
        $query = http_build_query(array_filter($parameters, static fn (mixed $value): bool => $value !== null));
        if ($query !== '') {
            $target .= '?'.$query;
        }
        if (Async::inCoroutine() && (Runtime::getHookFlags() & SWOOLE_HOOK_NATIVE_CURL) === 0) {
            throw new ServiceUnavailableException('Log agent HTTP requires the native coroutine CURL hook.');
        }
        $curl = curl_init($target);
        $body = '';
        curl_setopt_array($curl, [CURLOPT_HTTPHEADER => ['Authorization: Bearer '.trim($token), 'Accept: application/json'], CURLOPT_CONNECTTIMEOUT_MS => 1000, CURLOPT_TIMEOUT_MS => 2000, CURLOPT_FOLLOWLOCATION => false, CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 8388608) {
                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        }]);
        $success = curl_exec($curl);
        $status = $success === false ? 0 : curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        unset($curl);
        if ($status === 404) {
            throw new NotFoundException('Log record is no longer retained.');
        }
        if ($status === 422) {
            throw new InvalidInputException('The log agent rejected the query or its expired cursor.');
        }
        if ($status !== 200 || strlen($body) > 8388608) {
            throw new ServiceUnavailableException('Log node did not return a bounded response.');
        }
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ServiceUnavailableException('Log node returned an invalid response.', previous: $e);
        }
        if (!is_array($data)) {
            throw new ServiceUnavailableException('Log node returned an invalid response.');
        }

        return $data;
    }
}
