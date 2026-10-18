<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker\Autoscaling;

use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;

/** Read-only metadata from the effective Messenger endpoint, never its count scan. */
final class RedisQueueSampler
{
    private const float TIMEOUT_SECONDS = 0.2;
    private const string SCRIPT = <<<'LUA'
local stream = KEYS[1]
local queue = KEYS[2]
local group = ARGV[1]
local now = tonumber(ARGV[2])
local due = redis.call('ZCOUNT', queue, '-inf', now)
local future = redis.call('ZCOUNT', queue, '(' .. now, '+inf')
local oldestDue = redis.call('ZRANGEBYSCORE', queue, '-inf', now, 'WITHSCORES', 'LIMIT', 0, 1)
local dueScore = oldestDue[2] or ''
if redis.call('EXISTS', stream) == 0 then
    return {'uninitialized', '', '', tostring(due), tostring(future), '', dueScore}
end
-- XINFO STREAM materializes at most two entries; XRANGE below at most one.
-- Each admitted message is bounded by the transport codec's payload limit.
local info = redis.call('XINFO', 'STREAM', stream)
local groupCount = 0
for i = 1, #info, 2 do
    if info[i] == 'groups' then groupCount = info[i + 1] end
end
if groupCount > 16 then return {'unavailable', 'group_limit'} end
local groups = redis.call('XINFO', 'GROUPS', stream)
for _, details in ipairs(groups) do
    local name, lag, pending, last = '', '', '', '0-0'
    for i = 1, #details, 2 do
        local key, value = details[i], details[i + 1]
        if key == 'name' then name = value end
        if key == 'lag' and type(value) == 'number' and value >= 0 then lag = tostring(value) end
        if key == 'pending' then pending = tostring(value) end
        if key == 'last-delivered-id' then last = value end
    end
    if name == group then
        local unread = redis.call('XRANGE', stream, '(' .. last, '+', 'COUNT', 1)
        local first = ''
        if #unread > 0 then first = unread[1][1] end
        local state = 'known'
        if lag == '' then state = 'unknown_lag' end
        return {state, lag, pending, tostring(due), tostring(future), first, dueScore}
    end
end
return {'uninitialized', '', '', tostring(due), tostring(future), '', dueScore}
LUA;

    /** @param array<string, mixed> $options Effective transport options, before DSN query overrides. */
    public function __construct(
        #[\SensitiveParameter] private readonly string $dsn,
        private readonly array $options = [],
    ) {
    }

    public function sample(?float $monotonicNow = null, ?float $wallNow = null): QueueObservation
    {
        // Timestamp the beginning, so a slow command cannot make old evidence fresh.
        $sampledAt = $monotonicNow ?? hrtime(true) / 1e9;
        $wall = $wallNow ?? microtime(true);
        $redis = null;
        try {
            [$dsn, $options, $stream, $group] = $this->configuration();
            $redis = new class extends \Redis {
                public function connect(string $host, int $port = 6379, float $timeout = 0, ?string $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0, ?array $context = null): bool
                {
                    return parent::connect($host, $port, $timeout > 0 ? min(0.2, $timeout) : 0.2, null, 0, $read_timeout > 0 ? min(0.2, $read_timeout) : 0.2, $context);
                }

                public function pconnect(string $host, int $port = 6379, float $timeout = 0, ?string $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0, ?array $context = null): bool
                {
                    return $this->connect($host, $port, $timeout, null, 0, $read_timeout, $context);
                }
            };
            // Symfony owns TLS, auth, database and all other connection semantics.
            Connection::fromDsn($dsn, $options, $redis);
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, self::TIMEOUT_SECONDS);
            $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
            $result = $redis->eval(self::SCRIPT, [$stream, $stream . '__queue', $group, (string) floor($wall * 1000)], 2);
            if (!is_array($result) || count($result) > 7 || strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 1024) {
                throw new \UnexpectedValueException();
            }
            if (($result[0] ?? '') === 'unavailable') {
                return new QueueObservation('unavailable', $sampledAt, fault: 'group_limit');
            }
            if (count($result) !== 7 || !in_array($result[0], ['known', 'unknown_lag', 'uninitialized'], true)) {
                throw new \UnexpectedValueException();
            }
            return new QueueObservation(
                $result[0], $sampledAt,
                $this->count($result[1]), $this->count($result[2]), $this->count($result[3]), $this->count($result[4]),
                $result[5] !== '' ? max(0.0, $wall - (float) explode('-', $result[5], 2)[0] / 1000) : null,
                $result[6] !== '' ? max(0.0, $wall - (float) $result[6] / 1000) : null,
            );
        } catch (\Throwable) {
            // Exception messages may contain DSNs, credentials or payloads.
            return new QueueObservation('unavailable', $sampledAt, fault: 'redis_observation_unavailable');
        } finally {
            try {
                if ($redis instanceof \Redis && $redis->isConnected()) {
                    $redis->close();
                }
            } catch (\Throwable) {
                // Cleanup must preserve the redacted observation result.
            }
        }
    }

    /** @return array{string, array<string, mixed>, string, string} */
    private function configuration(): array
    {
        if (strlen($this->dsn) > 4096 || str_contains($this->dsn, ',')) {
            throw new \InvalidArgumentException();
        }
        $parts = parse_url($this->dsn);
        if ($parts === false) {
            throw new \InvalidArgumentException();
        }
        parse_str($parts['query'] ?? '', $query);
        $effective = array_replace($this->options, $query);
        foreach (['sentinel', 'redis_sentinel', 'sentinel_master', 'host'] as $unsupported) {
            if (isset($effective[$unsupported])) {
                throw new \InvalidArgumentException();
            }
        }
        if (filter_var($effective['cluster'] ?? false, FILTER_VALIDATE_BOOL)) {
            throw new \InvalidArgumentException();
        }
        $path = isset($parts['host']) ? explode('/', rtrim($parts['path'] ?? '', '/')) : [];
        $stream = $path[1] ?? $effective['stream'] ?? 'messages';
        $group = $path[2] ?? $effective['group'] ?? 'symfony';
        foreach ([$stream, $group] as $name) {
            if (!is_string($name) || $name === '' || strlen($name) > 256) {
                throw new \InvalidArgumentException();
            }
        }
        // Force eager initialization even when lazy appears in the DSN query.
        $dsn = $this->dsn . (str_contains($this->dsn, '?') ? '&' : '?') . 'lazy=0';
        return [$dsn, $this->options, $stream, $group];
    }

    private function count(mixed $value): ?int
    {
        if ($value === '') {
            return null;
        }
        $count = filter_var($value, FILTER_VALIDATE_INT);
        if ($count === false || $count < 0) {
            throw new \UnexpectedValueException();
        }
        return $count;
    }
}
