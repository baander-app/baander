<?php

declare(strict_types=1);

namespace Baander\Deployment\Presets;

/**
 * One address family's subnet on the edge network. The host's bridge takes the subnet's first host
 * address and the gateway its second; Docker assigns dynamic addresses from the upper half only, so
 * no container started before the gateway can take the gateway's address.
 */
final readonly class EdgeSubnet
{
    /** The host bits a subnet keeps at least: eight addresses hold the bridge, the gateway and a dynamic half. */
    private const int HOST_BITS = 3;

    private function __construct(
        public string $subnet,
        /** The host's own address on the edge bridge, which host-local clients of a published port arrive from. */
        public string $bridgeAddress,
        public string $gatewayAddress,
        /** The upper half of the subnet, the only range Docker assigns web's address from. */
        public string $dynamicRange,
        /** The packed network address. */
        private string $network,
        private int $prefix,
    ) {}

    /** The subnet when it is a network address of the family with the given address length that holds eight addresses or more. */
    public static function of(string $subnet, int $bits): ?self
    {
        [$network, $prefix] = self::parse($subnet) ?? [null, null];
        if ($network === null || strlen($network) * 8 !== $bits || $prefix > $bits - self::HOST_BITS || self::mask($network, $prefix) !== $network) {
            return null;
        }
        $upperHalf = $network;
        $upperHalf[intdiv($prefix, 8)] = chr(ord($network[intdiv($prefix, 8)]) | (0x80 >> ($prefix % 8)));

        return new self(
            subnet: $subnet,
            bridgeAddress: self::host($network, 1),
            gatewayAddress: self::host($network, 2),
            dynamicRange: self::text($upperHalf) . '/' . ($prefix + 1),
            network: $network,
            prefix: $prefix,
        );
    }

    /** Whether a subnet of the same family shares an address with this one; other families and malformed subnets never do. */
    public function overlaps(string $subnet): bool
    {
        [$other, $prefix] = self::parse($subnet) ?? [null, null];
        if ($other === null || strlen($other) !== strlen($this->network)) {
            return false;
        }
        $shared = min($this->prefix, $prefix);

        return self::mask($this->network, $shared) === self::mask($other, $shared);
    }

    /** @return array{string,int}|null The packed address and prefix length of a CIDR range. */
    private static function parse(string $subnet): ?array
    {
        $parts = explode('/', $subnet);
        if (count($parts) !== 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false || preg_match('/^(?:0|[1-9]\d{0,2})$/D', $parts[1]) !== 1) {
            return null;
        }
        $address = inet_pton($parts[0]);

        return $address === false || (int) $parts[1] > strlen($address) * 8 ? null : [$address, (int) $parts[1]];
    }

    /** The packed address with every bit after the prefix cleared. */
    private static function mask(string $address, int $prefix): string
    {
        $whole = intdiv($prefix, 8);
        $partial = $prefix % 8 === 0 ? '' : chr(ord($address[$whole]) & (0xFF00 >> ($prefix % 8)) & 0xFF);

        return str_pad(substr($address, 0, $whole) . $partial, strlen($address), "\0");
    }

    /** The address a small offset into the network; the network's low host bits are clear, so the offset sets them. */
    private static function host(string $network, int $offset): string
    {
        return self::text(substr($network, 0, -1) . chr(ord($network[-1]) | $offset));
    }

    private static function text(string $packed): string
    {
        return inet_ntop($packed) ?: throw new \LogicException('A packed address of four or sixteen bytes has a text form.');
    }
}
