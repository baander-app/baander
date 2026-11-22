<?php

declare(strict_types=1);

namespace Baander\Deployment\Presets;

/**
 * The network the preset gateway shares with web and nothing else. It carries an IPv4 subnet and, unless
 * the deployment runs IPv4-only, an IPv6 subnet; in each the gateway holds a fixed address outside the
 * range Docker assigns, so no container started before it can take that address, and web trusts exactly
 * the gateway's addresses as its proxy.
 */
final readonly class EdgeNetwork
{
    /** The IPv4 subnet when the configuration names none; start refuses it while another Docker network overlaps it. */
    public const string DEFAULT_SUBNET = '10.231.0.0/28';
    /** The IPv6 subnet when the configuration names none, a unique local range; start refuses it on overlap too. */
    public const string DEFAULT_SUBNET6 = 'fd00:baa:231::/64';

    private function __construct(
        public EdgeSubnet $ipv4,
        /** Null on an IPv4-only edge network. */
        public ?EdgeSubnet $ipv6,
    ) {}

    /** The network of the IPv4 subnet and, unless it is null, the IPv6 subnet. */
    public static function fromSubnets(string $ipv4, ?string $ipv6): self
    {
        return new self(
            EdgeSubnet::of($ipv4, bits: 32)
                ?? throw new \InvalidArgumentException('The edge network subnet must be an IPv4 network address with a prefix of /29 or shorter.'),
            $ipv6 === null ? null : (EdgeSubnet::of($ipv6, bits: 128)
                ?? throw new \InvalidArgumentException('The edge network subnet6 must be an IPv6 network address with a prefix of /125 or shorter.')),
        );
    }

    /** The Docker network name of a deployment's edge network. */
    public static function name(string $deployment): string
    {
        return $deployment . '-edge';
    }

    public function dualStack(): bool
    {
        return $this->ipv6 !== null;
    }

    /**
     * The subnet of each family the network carries, IPv4 first.
     *
     * @return list<EdgeSubnet>
     */
    public function subnets(): array
    {
        return $this->ipv6 === null ? [$this->ipv4] : [$this->ipv4, $this->ipv6];
    }

    /**
     * The gateway's address in each family, the only peers web takes forwarding headers from.
     *
     * @return list<string>
     */
    public function gatewayAddresses(): array
    {
        return array_map(static fn(EdgeSubnet $subnet): string => $subnet->gatewayAddress, $this->subnets());
    }

    /**
     * The host's bridge address in each family: host-local clients of an IPv4 published port arrive
     * from the IPv4 one, and those of an IPv6 published port from the IPv6 one.
     *
     * @return list<string>
     */
    public function bridgeAddresses(): array
    {
        return array_map(static fn(EdgeSubnet $subnet): string => $subnet->bridgeAddress, $this->subnets());
    }
}
