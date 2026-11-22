<?php

declare(strict_types=1);

namespace Baander\Deployment\Presets;

/**
 * The network the preset gateway shares with web and nothing else. It carries an IPv4 and an IPv6
 * subnet; in each the gateway holds a fixed address outside the range Docker assigns, so no container
 * started before it can take that address, and web trusts exactly those two addresses as its proxy.
 */
final readonly class EdgeNetwork
{
    /** The IPv4 subnet when the configuration names none; start refuses it while another Docker network overlaps it. */
    public const string DEFAULT_SUBNET = '10.231.0.0/28';
    /** The IPv6 subnet when the configuration names none, a unique local range; start refuses it on overlap too. */
    public const string DEFAULT_SUBNET6 = 'fd00:baa:231::/64';

    private function __construct(
        public EdgeSubnet $ipv4,
        public EdgeSubnet $ipv6,
    ) {}

    public static function fromSubnets(string $ipv4, string $ipv6): self
    {
        return new self(
            EdgeSubnet::of($ipv4, bits: 32)
                ?? throw new \InvalidArgumentException('The edge network subnet must be an IPv4 network address with a prefix of /29 or shorter.'),
            EdgeSubnet::of($ipv6, bits: 128)
                ?? throw new \InvalidArgumentException('The edge network subnet6 must be an IPv6 network address with a prefix of /125 or shorter.'),
        );
    }

    /** The Docker network name of a deployment's edge network. */
    public static function name(string $deployment): string
    {
        return $deployment . '-edge';
    }

    /**
     * The gateway's address in each family, the only peers web takes forwarding headers from.
     *
     * @return list<string>
     */
    public function gatewayAddresses(): array
    {
        return [$this->ipv4->gatewayAddress, $this->ipv6->gatewayAddress];
    }

    /**
     * The host's bridge address in each family: host-local clients of an IPv4 published port arrive
     * from the first, and those of an IPv6 published port from the second.
     *
     * @return list<string>
     */
    public function bridgeAddresses(): array
    {
        return [$this->ipv4->bridgeAddress, $this->ipv6->bridgeAddress];
    }
}
