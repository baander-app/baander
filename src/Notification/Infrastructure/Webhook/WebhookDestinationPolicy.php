<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Webhook;

use App\Notification\Application\Port\WebhookDestinationPortInterface;
use Baander\HttpClient\AddressClassifier;
use Symfony\Component\HttpFoundation\IpUtils;

final class WebhookDestinationPolicy implements WebhookDestinationPortInterface
{
    private const array LAN_RANGES = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'];

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
        if ($ips === [] || !array_all($ips, $this->admits(...))) {
            return null;
        }

        return ['host' => $host, 'ips' => $ips];
    }

    /** An IPv6 address that carries an IPv4 address is judged, and allowlisted, by that IPv4 address. */
    private function admits(string $ip): bool
    {
        $destinations = AddressClassifier::destinations($ip);

        return $destinations !== [] && array_all($destinations, $this->admitsDestination(...));
    }

    /** The allowlist opens only LAN ranges, never loopback, link-local, or metadata endpoints. */
    private function admitsDestination(string $address): bool
    {
        return AddressClassifier::isPublic($address) || (
            IpUtils::checkIp($address, self::LAN_RANGES) &&
            IpUtils::checkIp($address, array_filter($this->lanAllowlist) |> array_values(...))
        );
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
        return array_unique($ips) |> array_values(...);
    }
}
