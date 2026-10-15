<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Cache;

use App\Auth\Application\DTO\StoredWebSocketTicket;
use App\Auth\Application\DTO\WebSocketIdentity;
use App\Auth\Application\Exception\WebSocketTicketUnavailableException;
use App\Auth\Application\Port\WebSocketTicketStoreInterface;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Redis\RedisPoolExhaustedException;
use RedisException;

final readonly class RedisWebSocketTicketStore implements WebSocketTicketStoreInterface
{
    private const string CONSUME = <<<'LUA'
local value = redis.call('GET', KEYS[1])
if not value then return false end
local record = cjson.decode(value)
if record.origin ~= ARGV[1] or record.path ~= ARGV[2] then return false end
redis.call('DEL', KEYS[1])
return value
LUA;

    public function __construct(private RedisClientFactory $redisClientFactory) {}

    public function put(string $ticket, StoredWebSocketTicket $record): void
    {
        $payload = json_encode([
            'userId' => $record->identity->userId,
            'accessTokenId' => $record->identity->accessTokenId,
            'origin' => $record->origin,
            'path' => $record->path,
            'expiresAt' => $record->expiresAt->format('Y-m-d\TH:i:s.uP'),
        ], JSON_THROW_ON_ERROR);
        $milliseconds = $record->expiresAt->getTimestamp() * 1000 + (int) $record->expiresAt->format('v');
        try {
            $stored = $this->redisClientFactory->borrow(
                static fn(\Redis $redis): bool => $redis->set(self::key($ticket), $payload, ['NX', 'PXAT' => $milliseconds]) === true,
            );
        } catch (RedisException | RedisPoolExhaustedException $exception) {
            throw new WebSocketTicketUnavailableException('WebSocket ticket storage is unavailable.', previous: $exception);
        }
        if (!$stored) {
            throw new WebSocketTicketUnavailableException('WebSocket ticket storage refused the ticket.');
        }
    }

    public function consume(string $ticket, string $origin, string $path): ?StoredWebSocketTicket
    {
        try {
            $payload = $this->redisClientFactory->borrow(
                static fn(\Redis $redis): mixed => $redis->eval(self::CONSUME, [self::key($ticket), $origin, $path], 1),
            );
        } catch (RedisException | RedisPoolExhaustedException $exception) {
            throw new WebSocketTicketUnavailableException('WebSocket ticket storage is unavailable.', previous: $exception);
        }
        if ($payload === false || $payload === null) {
            return null;
        }
        try {
            if (!is_string($payload)) {
                throw new \UnexpectedValueException('Invalid ticket record.');
            }
            $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            foreach (['userId', 'accessTokenId', 'origin', 'path', 'expiresAt'] as $field) {
                if (!is_array($data) || !isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                    throw new \UnexpectedValueException('Invalid ticket record.');
                }
            }

            return new StoredWebSocketTicket(
                new WebSocketIdentity($data['userId'], $data['accessTokenId']),
                $data['origin'], $data['path'], new \DateTimeImmutable($data['expiresAt']),
            );
        } catch (\JsonException | \UnexpectedValueException | \DateMalformedStringException $exception) {
            throw new WebSocketTicketUnavailableException('WebSocket ticket record is unavailable.', previous: $exception);
        }
    }

    private static function key(string $ticket): string
    {
        return 'auth:websocket-ticket:' . hash('sha256', $ticket);
    }
}
