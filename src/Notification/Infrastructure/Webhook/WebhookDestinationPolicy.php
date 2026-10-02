<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Webhook;

use App\Notification\Application\Port\WebhookDestinationPortInterface;
use Symfony\Component\HttpFoundation\IpUtils;

final class WebhookDestinationPolicy implements WebhookDestinationPortInterface
{
    /**
     * @param list<string> $lanAllowlist IP addresses or CIDRs, configured by the operator
     * @param ?\Closure(string): list<string> $dnsResolver DNS lookup only; every result is validated below
     */
    public function __construct(
        private readonly array $lanAllowlist = [],
        private readonly ?\Closure $dnsResolver = null,
    ) {
    }

    public function resolve(string $url): ?array
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parsed = parse_url($url);
        if ($parsed === false || !in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'], true) ||
            isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['fragment'])) {
            return null;
        }
        $host = trim($parsed['host'] ?? '', '[]');
        if ($host === '') {
            return null;
        }
        $ips = $this->resolveHost($host);
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            $packed = inet_pton($ip);
            if ($packed !== false && strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
                $ip = inet_ntop(substr($packed, 12));
            }
            if ($ip === false) {
                return null;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false &&
                !IpUtils::checkIp($ip, ['224.0.0.0/4', 'ff00::/8'])) {
                continue;
            }
            // The allowlist opens only LAN ranges, never loopback, link-local, or metadata endpoints.
            if (!IpUtils::checkIp($ip, ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7']) ||
                !IpUtils::checkIp($ip, array_values(array_filter($this->lanAllowlist)))) {
                return null;
            }
        }

        return ['host' => $host, 'ips' => $ips];
    }

    /** @return list<string> */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        if ($this->dnsResolver !== null) {
            return ($this->dnsResolver)($host);
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }
        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ipv6'] ?? $record['ip'] ?? null;
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $ip;
            }
        }
        return array_values(array_unique($ips));
    }
}
