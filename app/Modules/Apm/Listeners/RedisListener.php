<?php

namespace App\Modules\Apm\Listeners;

use App\Modules\Apm\OctaneApmManager;
use Elastic\Apm\SpanInterface;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

class RedisListener
{
    /**
     * Constructor
     */
    public function __construct(private ?LoggerInterface $logger = null)
    {
    }

    /**
     * Handle Redis command executed event
     */
    public function handle(CommandExecuted $event): void
    {
        if (!config('apm.monitoring.redis', true)) {
            return;
        }

        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            if (!$manager->isEnabled()) {
                return;
            }

            $this->recordRedisCommand($manager, $event);
        } catch (Throwable $e) {
            Log::error('Failed to record Redis command in APM', [
                'error'      => $e->getMessage(),
                'command'    => $event->command,
                'connection' => $event->connectionName,
            ]);
        }
    }

    /**
     * Record Redis command execution
     */
    private function recordRedisCommand(OctaneApmManager $manager, CommandExecuted $event): void
    {
        $commandName = strtoupper($event->command);
        $spanName = "redis.{$commandName}";

        // Create span for the Redis operation
        $span = $manager->createSpan(
            name: $spanName,
            type: 'db',
            subtype: 'redis',
            action: 'query',
        );

        if (!$span) {
            return;
        }

        // Add Redis-specific context
        $this->addRedisContext($span, $event);

        // End the span immediately since the command has already executed
        $span->end($event->time);
    }

    /**
     * Add Redis-specific context to the span
     */
    private function addRedisContext(SpanInterface $span, CommandExecuted $event): void
    {
        // Add basic Redis information
        $span->context()->setLabel('redis.command', strtoupper($event->command));
        $span->context()->setLabel('redis.connection', $event->connectionName ?? 'default');
        $span->context()->setLabel('redis.duration_ms', number_format($event->time, 2));

        // Add command parameters (sanitized)
        if (!empty($event->parameters)) {
            $sanitizedParams = $this->sanitizeRedisParameters($event->command, $event->parameters);
            foreach ($sanitizedParams as $key => $value) {
                $span->context()->setLabel("redis.param.{$key}", $value);
            }
        }

        // Add database context if available
        $span->context()->db()->setStatement($this->buildRedisStatement($event));

        $span->setOutcome('success');
    }

    /**
     * Sanitize Redis parameters to avoid logging sensitive data
     */
    private function sanitizeRedisParameters(string $command, array $parameters): array
    {
        $sanitized = [];
        $command = strtoupper($command);

        // Define sensitive commands that should have their values hidden
        $sensitiveCommands = ['AUTH', 'CONFIG', 'EVAL', 'EVALSHA'];
        $shouldSanitize = in_array($command, $sensitiveCommands);

        foreach ($parameters as $index => $param) {
            $key = "param_{$index}";

            if ($shouldSanitize && $index > 0) {
                // Hide values for sensitive commands, keep first param (usually key name)
                $sanitized[$key] = '[REDACTED]';
            } else if (is_string($param) && strlen($param) > 1000) {
                // Truncate very long parameters
                $sanitized[$key] = substr($param, 0, 1000) . '...[TRUNCATED]';
            } else if (is_array($param)) {
                $sanitized[$key] = '[ARRAY:' . count($param) . ']';
            } else if (is_object($param)) {
                $sanitized[$key] = '[OBJECT:' . get_class($param) . ']';
            } else {
                $sanitized[$key] = (string)$param;
            }
        }

        return $sanitized;
    }

    /**
     * Build Redis statement for database context
     */
    private function buildRedisStatement(CommandExecuted $event): string
    {
        $command = strtoupper($event->command);

        if (empty($event->parameters)) {
            return $command;
        }

        // For some commands, include the key name
        $keyCommands = ['GET', 'SET', 'DEL', 'EXISTS', 'EXPIRE', 'TTL', 'INCR', 'DECR'];

        if (in_array($command, $keyCommands) && isset($event->parameters[0])) {
            return "{$command} {$event->parameters[0]}";
        }

        // For list/hash commands, include key and field if available
        $keyFieldCommands = ['HGET', 'HSET', 'HDEL', 'LPUSH', 'RPUSH', 'LPOP', 'RPOP'];

        if (in_array($command, $keyFieldCommands)) {
            $parts = [$command];

            if (isset($event->parameters[0])) {
                $parts[] = $event->parameters[0];
            }

            if (isset($event->parameters[1]) && !in_array($command, ['LPOP', 'RPOP'])) {
                $parts[] = $event->parameters[1];
            }

            return implode(' ', $parts);
        }

        // For other commands, just return the command name with parameter count
        $paramCount = count($event->parameters);
        return "{$command} ({$paramCount} params)";
    }
}