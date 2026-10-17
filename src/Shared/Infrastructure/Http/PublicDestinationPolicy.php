<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\IpUtils;

final class PublicDestinationPolicy
{
    /** @param ?\Closure(string, float): list<string> $dnsResolver */
    public function __construct(
        private readonly ?\Closure $dnsResolver = null,
        private readonly NativeDnsResolver $nativeResolver = new NativeDnsResolver(),
    ) {
    }

    /**
     * @param list<string> $allowedHosts Exact hostnames
     * @param list<string> $allowedDomainSuffixes Domains including their subdomains
     * @return array{host: string, ips: non-empty-list<string>}
     */
    public function resolve(string $url, array $allowedHosts, array $allowedDomainSuffixes = [], ?float $deadline = null): array
    {
        $parts = parse_url($url);
        if ($parts === false || filter_var($url, FILTER_VALIDATE_URL) === false ||
            strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['port'] ?? 443) !== 443 ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) ||
            preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new UnsafeDestinationException();
        }
        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || !str_contains($host, '.') || str_ends_with($host, '.') ||
            filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false ||
            filter_var($host, FILTER_VALIDATE_IP) !== false || !$this->isAllowed($host, $allowedHosts, $allowedDomainSuffixes)) {
            throw new UnsafeDestinationException();
        }

        $deadline ??= hrtime(true) / 1e9 + 30;
        if (!is_finite($deadline) || $deadline <= hrtime(true) / 1e9) {
            throw new UnsafeDestinationException();
        }
        try {
            $ips = $this->dnsResolver !== null
                ? ($this->dnsResolver)($host, $deadline)
                : $this->nativeResolver->resolve($host, $deadline);
        } catch (\Throwable) {
            throw new UnsafeDestinationException();
        }
        if ($ips === [] || hrtime(true) / 1e9 >= $deadline) {
            throw new UnsafeDestinationException();
        }
        $approved = [];
        foreach ($ips as $ip) {
            $packed = @inet_pton($ip);
            if ($packed === false) {
                throw new UnsafeDestinationException();
            }
            // Classify mapped IPv4 by its embedded address, never by the wrapper.
            if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
                $ip = (string) inet_ntop(substr($packed, 12));
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false ||
                IpUtils::checkIp($ip, ['224.0.0.0/4', 'ff00::/8', '64:ff9b::/96', '64:ff9b:1::/48', '2002::/16', '2001::/32'])) {
                throw new UnsafeDestinationException();
            }
            $approved[] = $ip;
        }

        return ['host' => $host, 'ips' => array_values(array_unique($approved))];
    }

    /**
     * @param list<string> $hosts
     * @param list<string> $suffixes
     */
    private function isAllowed(string $host, array $hosts, array $suffixes): bool
    {
        if (in_array($host, $hosts, true)) {
            return true;
        }
        foreach ($suffixes as $suffix) {
            if ($suffix !== '' && ($host === $suffix || str_ends_with($host, '.' . $suffix))) {
                return true;
            }
        }
        return false;
    }
}
