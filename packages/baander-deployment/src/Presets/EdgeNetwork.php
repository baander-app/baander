<?php

declare(strict_types=1);

namespace Baander\Deployment\Presets;

/**
 * The network the preset gateway shares with web and nothing else. The gateway holds a fixed
 * address outside the range Docker assigns, so no container started before it can take that
 * address, and web trusts exactly that address as its proxy.
 */
final readonly class EdgeNetwork
{
    /** The subnet when the configuration names none; start refuses it while another Docker network overlaps it. */
    public const string DEFAULT_SUBNET = '10.231.0.0/28';

    /** The longest prefix that still holds the bridge, the gateway and a range for web's address. */
    private const int LONGEST_PREFIX = 29;

    private function __construct(
        public string $subnet,
        /** The host's own address on the edge bridge, which host-local clients of a published port arrive from. */
        public string $bridgeAddress,
        public string $gatewayAddress,
        /** The upper half of the subnet, the only range Docker assigns web's address from. */
        public string $dynamicRange,
        private int $base,
        private int $prefix,
    ) {}

    public static function fromSubnet(string $subnet): self
    {
        $network = self::parse($subnet);
        if ($network === null || $network['prefix'] > self::LONGEST_PREFIX || self::mask($network['address'], $network['prefix']) !== $network['address']) {
            throw new \InvalidArgumentException('The edge network subnet must be an IPv4 network address with a prefix of /29 or shorter.');
        }
        ['address' => $base, 'prefix' => $prefix] = $network;

        return new self(
            subnet: $subnet,
            bridgeAddress: long2ip($base + 1),
            gatewayAddress: long2ip($base + 2),
            dynamicRange: long2ip($base + (1 << (31 - $prefix))) . '/' . ($prefix + 1),
            base: $base,
            prefix: $prefix,
        );
    }

    /** The Docker network name of a deployment's edge network. */
    public static function name(string $deployment): string
    {
        return $deployment . '-edge';
    }

    /** Whether an IPv4 subnet shares an address with this one; IPv6 and malformed subnets never do. */
    public function overlaps(string $subnet): bool
    {
        $other = self::parse($subnet);
        if ($other === null) {
            return false;
        }
        $shared = min($this->prefix, $other['prefix']);

        return self::mask($this->base, $shared) === self::mask($other['address'], $shared);
    }

    /** @return array{address:int,prefix:int}|null */
    private static function parse(string $subnet): ?array
    {
        $parts = explode('/', $subnet);
        if (count($parts) !== 2 || preg_match('/^(?:3[0-2]|[12]?\d)$/D', $parts[1]) !== 1) {
            return null;
        }
        $address = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? false : ip2long($parts[0]);

        return $address === false ? null : ['address' => $address, 'prefix' => (int) $parts[1]];
    }

    private static function mask(int $address, int $prefix): int
    {
        return $prefix === 0 ? 0 : $address & (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
    }
}
