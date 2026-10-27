<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Web;

use Baander\Tooling\Fixtures\Runtime;

final class ShutdownAssertions
{
    /**
     * @return list<array{source:string,time:float,event:string,details:string}>
     */

    public static function events(string $text): array
    {
        $events = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(probe|client|drill) ([0-9]+(?:\.[0-9]+)?) (\S+)(?: (.*))?$/D', trim($line), $match) === 1) {
                $events[] = [
                    'source' => $match[1],
                    'time' => (float) $match[2],
                    'event' => $match[3],
                    'details' => $match[4] ?? '',
                ];
            }
        }
        return $events;
    }
    /**
     * @return list<array{source:string,time:float,event:string,details:string}>
     */

    public static function selected(string $text, string $source, string $event): array
    {
        return self::events($text)
            |> (static fn(array $events): array => array_filter(
                $events,
                static fn(array $entry): bool => $entry['source'] === $source && $entry['event'] === $event,
            ))
            |> array_values(...);
    }
    /**
     * @return array{source:string,time:float,event:string,details:string}
     */

    public static function first(string $text, string $source, string $event): array
    {
        $events = self::selected($text, $source, $event);
        Runtime::require($events !== [], "Missing {$source} {$event} event: {$text}");
        return $events[0];
    }
    /**
     * @return array{source:string,time:float,event:string,details:string}
     */

    public static function last(string $text, string $source, string $event): array
    {
        $events = self::selected($text, $source, $event);
        Runtime::require($events !== [], "Missing {$source} {$event} event: {$text}");
        return $events[array_key_last($events)];
    }
    public static function probe(string $text): void
    {
        $before = self::first($text, 'probe', 'before_shutdown')['time'];
        $holdEnd = self::first($text, 'probe', 'master_hold_end')['time'];
        $workerStop = self::selected($text, 'probe', 'worker_stop')
            |> (static fn(array $events): array => array_column($events, 'time'))
            |> min(...);
        Runtime::require(
            count(self::selected($text, 'probe', 'before_shutdown')) === 1,
            'onBeforeShutdown ran more than once.',
        );
        Runtime::require(
            str_contains($text, ' worker_shutdown_call result=true'),
            'The worker did not call Server::shutdown() during the drain.',
        );
        $response = self::first($text, 'client', 'hold_response');
        Runtime::require(
            $before <= $response['time'] && $response['time'] <= $holdEnd,
            'Held response did not arrive during the master timer.',
        );
        Runtime::require(
            $response['details'] === 'status=200 body=drained errno=0',
            'Invalid held response: ' . $response['details'],
        );
        $close = self::first($text, 'client', 'ws_close_frame');
        Runtime::require(
            $before <= $close['time'] && $close['time'] <= $holdEnd,
            'WebSocket close frame did not arrive during the master timer.',
        );
        Runtime::require($close['details'] === 'code=1012 reason=restart', 'Invalid probe close frame.');
        $keepalive = self::first($text, 'client', 'keepalive_during_drain');
        Runtime::require(
            $keepalive['time'] <= $holdEnd && $keepalive['details'] === 'status=200 body=pong errno=0',
            'Kept-alive request was not served during the master timer.',
        );
        $connections = self::selected($text, 'probe', 'ping')
            |> (static fn(array $events): array => array_map(static fn(array $entry): string => explode(' ', $entry['details'])[0], $events))
            |> array_unique(...);
        Runtime::require(count($connections) === 1, 'Probe requests did not reuse the kept-alive connection.');
        Runtime::require(
            $holdEnd <= self::first($text, 'client', 'idle_eof')['time'] && $holdEnd <= $workerStop,
            'Connections or workers stopped during the master timer.',
        );
        foreach (self::selected($text, 'probe', 'worker_stop') as $event) {
            Runtime::require(
                str_ends_with($event['details'], 'master_wchan=do_wait master_threads=1 connections=0'),
                'Worker stopped before reactor threads joined, connections closed and manager wait began.',
            );
        }
        Runtime::require(
            array_any(
                self::selected($text, 'probe', 'shutdown'),
                static fn(array $event): bool => $event['details'] === '',
            ),
            'onShutdown did not run.',
        );
    }
    public static function timeline(string $text, float $firstSignal): string
    {
        $events = self::events($text);
        usort($events, static fn(array $left, array $right): int => $left['time'] <=> $right['time']);
        return implode(
            "\n",
            array_map(
                static fn(array $entry): string => sprintf(
                    '  %+10.6f  %-6s %s%s',
                    $entry['time'] - $firstSignal,
                    $entry['source'],
                    $entry['event'],
                    $entry['details'] === '' ? '' : ' ' . $entry['details'],
                ),
                $events,
            ),
        ) . "\n";
    }
    public static function drainCount(string $log, int $exitCode): void
    {
        Runtime::require(
            substr_count($log, 'Web server drain started: deadline 10 s, window 3 s, worker stop wait 1 s.') === 1,
            'The log does not show exactly one drain start.',
        );
        if ($exitCode === 0) {
            Runtime::require(
                substr_count($log, 'Swoole HTTP Server has been successfully shutdown') === 1,
                'The log does not show exactly one Swoole shutdown.',
            );
        }
    }
    public static function drainedWindow(string $log, bool $early = false): float
    {
        Runtime::require(
            preg_match(
                '/^Drain window closed after ([0-9.]+) s, every worker drained; reset [0-9]+ client connection\(s\)\.$/m',
                $log,
                $match,
            ) === 1,
            'The drain window did not close with every worker drained: ' . $log,
        );
        $window = (float) $match[1];
        Runtime::require(!$early || $window < 1, 'Workers took too long to report drained: ' . $window);
        return $window;
    }
    public static function websocket(string $log): void
    {
        $events = self::events($log);
        $closeIndex = $closedIndex = null;
        foreach ($events as $index => $event) {
            if ($event['event'] === 'ws_close_frame' && $closeIndex === null) {
                $closeIndex = $index;
                Runtime::require(
                    $event['details'] === 'code=1012 reason=service restart',
                    'WebSocket received no service-restart close frame: ' . $log,
                );
            }
            if ($event['event'] === 'ws_closed' && $closedIndex === null) {
                $closedIndex = $index;
            }
        }
        Runtime::require(
            $closeIndex !== null && ($closedIndex === null || $closeIndex < $closedIndex),
            'WebSocket ended before its 1012 close frame: ' . $log,
        );
    }
    public static function keepalive(string $log, bool $live): void
    {
        $event = $live ? 'live' : 'ready';
        $first = self::first($log, 'client', $event)['details'];
        $last = self::last($log, 'client', $event)['details'];
        $expectedFirst = $live ? 'status=200 connection=keep-alive draining=false' : 'status=200 connection=keep-alive';
        $expectedLast = $live ? 'status=200 connection=close draining=true' : 'status=503 connection=close';
        Runtime::require(
            $first === $expectedFirst && $last === $expectedLast && self::selected($log, 'client', $event . '_eof') === [],
            'Kept-alive /' . $event . ' did not report its drain response: ' . $log,
        );
    }
    /**
     * @return array{peak:float,limit:float}|null
     */

    public static function memory(string $health): ?array
    {
        if (preg_match(
            '/"component":"memory","status":"[a-z_]+","responseTimeMs":[0-9.]+,"details":\{"workerId":[0-9]+,"usageMb":([0-9.]+),"limitMb":([0-9.]+),/',
            $health,
            $match,
        ) !== 1) {
            return null;
        }
        return ['peak' => (float) $match[1], 'limit' => (float) $match[2]];
    }
    public static function deliveryOnly(string $status, string $processes): void
    {
        $server = json_decode($status, true, flags: JSON_THROW_ON_ERROR);
        Runtime::require(
            ($server['server']['settings']['task_worker_num'] ?? 0) === 0,
            'The web server enabled task workers.',
        );
        foreach (explode("\n", trim($processes)) as $line) {
            if (preg_match('/^\s*Z/', $line) !== 1) {
                Runtime::require(
                    preg_match(
                        '~cpu-pool-worker|(?:^|[\s/])(?:ffmpeg|ffprobe|nvidia-smi)(?:\s|$)|bin/worker-(?:media|video|audio)[.]php~',
                        $line,
                    ) !== 1,
                    'The web container started a media executor or hardware probe: ' . $line,
                );
            }
        }
    }
}
