<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer\Agent;

use App\Shared\Application\LogViewer\LogQuery;
use App\Shared\Infrastructure\LogViewer\LogIndex;

final readonly class AgentProtocol
{
    public function __construct(private AgentConfiguration $config, private LogIndex $index)
    {
    }

    /** @param array<string, mixed> $query
     * @return array{int,array<string,mixed>}
     */
    public function handle(string $method, string $path, array $query, string $authorization): array
    {
        if (!hash_equals('Bearer '.$this->config->token(), $authorization)) {
            return [401, ['error' => 'Unauthorized.']];
        }
        if ('GET' !== $method) {
            return [405, ['error' => 'Method not allowed.']];
        }
        try {
            return match (true) {
                '/health' === $path => [200, ['node' => $this->config->get('nodeId'), 'status' => 'healthy']],
                '/sources' === $path => [200, $this->index->sources()],
                '/entries' === $path => [200, $this->index->query($this->query($query))],
                1 === preg_match('~^/entry/([A-Za-z0-9_.:-]+)$~D', $path, $matches) => [200, $this->index->detail($matches[1])],
                default => [404, ['error' => 'Unknown agent operation.']],
            };
        } catch (\InvalidArgumentException|\App\Shared\Application\Exception\InvalidInputException $error) {
            return [422, ['error' => $error->getMessage()]];
        } catch (\Throwable) {
            return [503, ['error' => 'Agent index unavailable or snapshot expired.']];
        }
    }

    /** @param array<string, mixed> $input */
    private function query(array $input): LogQuery
    {
        $allowed = ['sources', 'search', 'levels', 'channels', 'correlationId', 'since', 'until', 'limit', 'cursor', 'order'];
        foreach ($input as $key => $value) {
            if ('channels' === $key) {
                if (!is_array($value) || !array_is_list($value) || count($value) > 1000) {
                    throw new \InvalidArgumentException('Channels must be a list.');
                }
                foreach ($value as $channel) {
                    if (!is_string($channel) || '' === $channel || strlen($channel) > 512 || str_contains($channel, "\0")) {
                        throw new \InvalidArgumentException('Invalid channel selection.');
                    }
                }
                continue;
            }
            if (!in_array($key, $allowed, true) || !is_string($value) || strlen($value) > 16384) {
                throw new \InvalidArgumentException('Invalid query parameter.');
            }
        }
        $sources = isset($input['sources']) && '' !== $input['sources'] ? explode(',', $input['sources']) : [];
        $known = array_column($this->index->sources()['items'], 'id');
        if ([] !== array_diff($sources, $known)) {
            throw new \InvalidArgumentException('Unknown source.');
        }
        $limit = $input['limit'] ?? '200';
        if (!ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 500) {
            throw new \InvalidArgumentException('Page limit must be between 1 and 500.');
        }

        return new LogQuery(sources: $sources, search: $input['search'] ?? '', levels: isset($input['levels']) && '' !== $input['levels'] ? explode(',', $input['levels']) : [], channels: $input['channels'] ?? [], correlationId: $input['correlationId'] ?? null, since: $input['since'] ?? null, until: $input['until'] ?? null, limit: (int) $limit, cursor: $input['cursor'] ?? null, order: $input['order'] ?? 'desc');
    }
}
