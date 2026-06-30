<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Log;

final readonly class JsonAccessLogFormatter implements AccessLogFormatter
{
    public function format(AccessLogDataMap $map): string
    {
        $data = [
            'method' => $map->getMethod(),
            'path' => $map->getPath(),
            'query' => $map->getQuery(),
            'status' => $map->getStatus(),
            'duration_us' => $map->getRequestDuration('us'),
            'client_ip' => $map->getClientIp(),
            'user_agent' => $map->getRequestHeader('User-Agent') ?: null,
            'referer' => $map->getRequestHeader('Referer') ?: null,
            'protocol' => $map->getProtocol(),
            'host' => $map->getHost(),
        ];

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
