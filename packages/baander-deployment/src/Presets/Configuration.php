<?php

declare(strict_types=1);

namespace Baander\Deployment\Presets;

use Baander\Core\EnvironmentVariable;
use Baander\Core\Secrets;
use Baander\Configuration\Images\ImageSelection;
use Baander\Configuration\Sources\JsonDocument;
use Baander\Deployment\Profiles\ImageProfile;

final readonly class Configuration
{
    public const array IMAGE_ROLES = ['application', 'worker', 'operator', 'postgres', 'gateway', 'redis'];
    public const array VOLUME_ROLES = ['media', 'storage', 'conversions', 'transcode_state', 'postgres', 'redis', 'secrets', 'pooler_credentials', 'redis_credentials'];
    /** Redis's own dataset bound, enforced by Redis itself rather than by a container limit. */
    public const array RESOURCE_DEFAULTS = ['redisMaxmemoryMiB' => 192];
    /** Size settings every deployment states; the image validates the process settings' bounds. */
    public const array REQUIRED_SIZE_KEYS = ['webWorkers', 'databaseSlots', 'ordinaryConsumers', 'sharedBuffersMiB', 'workMemMiB'];
    /** Media settings per capability: remux requires copy slots; unset transcode slots take the image's defaults. */
    public const array MEDIA_SIZE_KEYS = ['remux' => ['copySlots'], 'transcode' => ['videoSlots', 'audioSlots']];
    /**
     * Process size bounds as [minimum, maximum]. The image's deployment profile enforces the same
     * bounds; checking them here refuses a bad size before a running deployment is stopped.
     */
    public const array PROCESS_BOUNDS = ['webWorkers' => [1, 16], 'databaseSlots' => [1, 16], 'ordinaryConsumers' => [1, 4], 'copySlots' => [1, 8]];
    /** PostgreSQL memory bounds in MiB, as [minimum, maximum]. */
    public const array POSTGRES_BOUNDS = ['sharedBuffersMiB' => [32, 16384], 'workMemMiB' => [1, 1024]];
    public const array HARDWARE_KEYS = ['accelerator', 'device', 'group'];
    /** The prefix of the example configurations' placeholder values. */
    public const string PLACEHOLDER_PREFIX = Secrets::PLACEHOLDER_PREFIX;
    /** The secrets the containers refuse to boot without; the TOTP key is base64. */
    public const array SECRETS = [EnvironmentVariable::APP_SECRET => false, EnvironmentVariable::STREAM_URL_HMAC_SECRET => false, EnvironmentVariable::AUTH_TOTP_ENCRYPTION_KEY => true];
    /** The service passwords the preset gives PostgreSQL and Redis. */
    public const array PASSWORDS = ['DB_PASSWORD', EnvironmentVariable::REDIS_PASSWORD];
    /** @param list<string> $frontProxies Addresses and CIDR ranges of the reverse proxies in front of the gateway.
     * @param array<string,ImageSelection> $images
     * @param array<string,string> $environment
     * @param array<string,int> $resources
     * @param array<string,string> $volumes
     * @param array<string,int> $size Process, media and PostgreSQL size settings.
     * @param array{accelerator:string,device?:string,group?:int}|null $hardwareEncoder A transcode deployment's hardware encoder.
     */
    private function __construct(
        public string $name,
        public string $capability,
        public string $origin,
        public string $listenAddress,
        public int $listenPort,
        public array $frontProxies,
        public EdgeNetwork $edge,
        public array $images,
        public array $environment,
        public array $resources,
        public array $volumes,
        public array $size,
        public ?array $hardwareEncoder,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        JsonDocument::keys($data, ['version', 'name', 'capability', 'size', 'hardware', 'origin', 'listen', 'network', 'images', 'environment', 'resources', 'volumes'], 'preset configuration');
        if (($data['version'] ?? null) !== 2) {
            throw new \InvalidArgumentException('Unsupported preset configuration version.');
        }
        $name = self::string($data, 'name');
        if (preg_match('/^[a-z][a-z0-9-]{2,40}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Deployment name requires 3–41 lowercase letters, digits or hyphens.');
        }
        $capability = self::string($data, 'capability');
        if (!in_array($capability, ImageProfile::CAPABILITIES, true)) {
            throw new \InvalidArgumentException('Select the transcode or remux deployment capability.');
        }
        $size = self::size(self::map($data, 'size'), $capability);
        $hardware = null;
        if (array_key_exists('hardware', $data)) {
            if ($capability !== 'transcode') {
                throw new \InvalidArgumentException('A remux deployment runs no hardware encoder.');
            }
            $hardware = self::hardwareEncoder(self::map($data, 'hardware'));
        }
        $origin = self::string($data, 'origin');
        $url = parse_url($origin);
        if (!is_array($url) || !in_array($url['scheme'] ?? null, ['http', 'https'], true) || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || filter_var($origin, FILTER_VALIDATE_URL) === false || preg_match('/[\x00-\x20\x7f]/', $origin) === 1 || isset($url['query']) || isset($url['fragment']) || !in_array($url['path'] ?? '', ['', '/'], true)) {
            throw new \InvalidArgumentException('Origin must be an HTTP(S) origin without credentials, query or path.');
        }
        $listen = self::map($data, 'listen');
        JsonDocument::keys($listen, ['address', 'port', 'frontProxies'], 'listen configuration');
        $address = self::string($listen, 'address');
        if (filter_var($address, FILTER_VALIDATE_IP) === false || !is_int($listen['port'] ?? null) || $listen['port'] < 1 || $listen['port'] > 65535) {
            throw new \InvalidArgumentException('Listen requires an IP address and TCP port.');
        }
        $frontProxies = self::frontProxies($listen['frontProxies'] ?? []);
        $edge = self::edgeNetwork($data);
        $images = self::map($data, 'images');
        JsonDocument::keys($images, self::IMAGE_ROLES, 'deployment images');
        $selections = [];
        foreach (self::IMAGE_ROLES as $role) {
            $selections[$role] = new ImageSelection(self::string($images, $role), []);
        }
        $environment = self::map($data, 'environment');
        foreach ($environment as $key => $value) {
            if (!is_string($value) || preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new \InvalidArgumentException('Environment must map variable names to strings.');
            }
        }
        foreach ([EnvironmentVariable::APP_SECRET, EnvironmentVariable::STREAM_URL_HMAC_SECRET, EnvironmentVariable::AUTH_TOTP_ENCRYPTION_KEY, 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE', EnvironmentVariable::REDIS_PASSWORD] as $key) {
            self::string($environment, $key);
        }
        if (array_key_exists(EnvironmentVariable::TRUSTED_PROXIES, $environment)) {
            throw new \InvalidArgumentException(
                'Deployment derives TRUSTED_PROXIES from the gateway; declare reverse proxies in front of the gateway in listen.frontProxies.',
            );
        }
        foreach ([EnvironmentVariable::BAANDER_DEPLOYMENT_PROFILE, EnvironmentVariable::APP_ENV, EnvironmentVariable::APP_DEBUG, EnvironmentVariable::DATABASE_URL, EnvironmentVariable::DATABASE_CONTROL_URL, EnvironmentVariable::REDIS_URL, EnvironmentVariable::MESSENGER_TRANSPORT_DSN, EnvironmentVariable::APP_URL, EnvironmentVariable::DEFAULT_URI, EnvironmentVariable::APP_DOMAIN, EnvironmentVariable::MEDIA_STORAGE_PATH, EnvironmentVariable::CONVERT_STORAGE_PATH, EnvironmentVariable::APP_HOME, EnvironmentVariable::OAUTH_PRIVATE_KEY_PATH, EnvironmentVariable::OAUTH_PUBLIC_KEY_PATH] as $key) {
            if (array_key_exists($key, $environment)) {
                throw new \InvalidArgumentException('Deployment derives environment variable ' . $key . '.');
            }
        }
        $resources = $data['resources'] ?? [];
        if (!is_array($resources)) {
            throw new \InvalidArgumentException('Resources must be an object.');
        }
        JsonDocument::keys($resources, array_keys(self::RESOURCE_DEFAULTS), 'deployment resources');
        $resources = array_replace(self::RESOURCE_DEFAULTS, $resources);
        foreach ($resources as $value) {
            if (!is_int($value) || $value < 1 || $value > 1048576) {
                throw new \InvalidArgumentException('The Redis dataset bound must be a positive integer MiB.');
            }
        }
        $volumes = $data['volumes'] ?? [];
        if (!is_array($volumes)) {
            throw new \InvalidArgumentException('Volumes must be an object.');
        }
        JsonDocument::keys($volumes, self::VOLUME_ROLES, 'deployment volumes');
        foreach (self::VOLUME_ROLES as $role) {
            $volumes[$role] ??= $name . '-' . str_replace('_', '-', $role);
            if (!is_string($volumes[$role]) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{1,127}$/D', $volumes[$role]) !== 1) {
                throw new \InvalidArgumentException('Persistent storage requires named Docker volumes.');
            }
        }
        if (array_unique($volumes) |> count(...) !== count($volumes)) {
            throw new \InvalidArgumentException('Persistent volume roles must have distinct names.');
        }
        return new self($name, $capability, rtrim($origin, '/'), $address, $listen['port'], $frontProxies, $edge, $selections, $environment, $resources, $volumes, $size, $hardware);
    }

    /**
     * The peers whose forwarding headers the gateway accepts: the declared front proxies and, for a
     * loopback listener, the edge bridge addresses that host-local clients of the published port arrive from.
     *
     * @return list<string>
     */
    public function gatewayFrontProxies(): array
    {
        return $this->listensOnLoopback() ? [...$this->frontProxies, ...$this->edge->bridgeAddresses()] : $this->frontProxies;
    }

    public function listensOnLoopback(): bool
    {
        if (filter_var($this->listenAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($this->listenAddress, '127.');
        }

        return inet_pton($this->listenAddress) === inet_pton('::1');
    }

    /**
     * Refuses a configuration that still carries an example's `replace-with-` placeholder, so
     * a copied example cannot be deployed: the shipped examples parse; they do not deploy. It
     * also refuses the secrets and passwords the containers would refuse to boot with, by the
     * rules they apply ({@see Secrets}), and any secret or password the repository commits,
     * the test values the containers accept included. Problems name the variable alone.
     */
    public function assertDeployable(): void
    {
        foreach ($this->environment as $key => $value) {
            if (str_starts_with($value, self::PLACEHOLDER_PREFIX)) {
                throw new \InvalidArgumentException('Replace the placeholder value of ' . $key . ' before deploying.');
            }
        }
        $problems = [];
        foreach (self::SECRETS as $variable => $base64) {
            $problems[] = Secrets::secretProblem($variable, $this->environment[$variable] ?? '', $base64);
        }
        foreach (self::PASSWORDS as $variable) {
            $problems[] = Secrets::passwordProblem($variable, $this->environment[$variable] ?? '');
        }
        foreach ([...array_keys(self::SECRETS), ...self::PASSWORDS] as $variable) {
            if (Secrets::committed($this->environment[$variable] ?? '')) {
                $problems[] = $variable . ' is a value the repository commits.';
            }
        }
        $problems = array_filter($problems)
            |> array_unique(...)
            |> array_values(...);
        if ($problems !== []) {
            throw new \InvalidArgumentException('Generate private values before deploying: ' . implode(' ', $problems));
        }
    }

    /**
     * The deployment profile settings the images build their profile from; unset transcode slots
     * take the image's defaults.
     *
     * @return array{capability:string,webWorkers:int,doctrinePoolSlots:int,ordinaryConsumerLimit:int,sharedCopySlots:int,videoEncoderSlots:?int,audioEncoderSlots:?int,hardware:array{accelerator:string,device:?string}|null}
     */
    public function profileSettings(): array
    {
        return [
            'capability' => $this->capability,
            'webWorkers' => $this->size['webWorkers'],
            'doctrinePoolSlots' => $this->size['databaseSlots'],
            'ordinaryConsumerLimit' => $this->size['ordinaryConsumers'],
            'sharedCopySlots' => $this->size['copySlots'] ?? 0,
            'videoEncoderSlots' => $this->size['videoSlots'] ?? null,
            'audioEncoderSlots' => $this->size['audioSlots'] ?? null,
            'hardware' => $this->hardware(),
        ];
    }

    /**
     * The hardware encoder the deployment profile carries, without the host-specific group.
     *
     * @return array{accelerator:string,device:?string}|null
     */
    public function hardware(): ?array
    {
        return $this->hardwareEncoder === null ? null : ['accelerator' => $this->hardwareEncoder['accelerator'], 'device' => $this->hardwareEncoder['device'] ?? null];
    }

    /** The group that opens the hardware encoder device, when the operator overrides the device's own group. */
    public function hardwareGroup(): ?int
    {
        return $this->hardwareEncoder['group'] ?? null;
    }

    /** @param array<string,mixed> $size
     * @return array<string,int>
     */
    private static function size(array $size, string $capability): array
    {
        $media = self::MEDIA_SIZE_KEYS[$capability];
        JsonDocument::keys($size, [...self::REQUIRED_SIZE_KEYS, ...$media], 'deployment size for ' . $capability);
        foreach ([...self::REQUIRED_SIZE_KEYS, ...($capability === 'remux' ? $media : [])] as $key) {
            if (!array_key_exists($key, $size)) {
                throw new \InvalidArgumentException('Deployment size requires ' . $key . '.');
            }
        }
        foreach ($size as $key => $value) {
            if (!is_int($value) || $value < 0) {
                throw new \InvalidArgumentException('Deployment size ' . $key . ' must be a non-negative integer.');
            }
        }
        foreach (array_intersect_key(self::PROCESS_BOUNDS + self::POSTGRES_BOUNDS, $size) as $key => [$minimum, $maximum]) {
            if ($size[$key] < $minimum || $size[$key] > $maximum) {
                throw new \InvalidArgumentException(sprintf('Deployment size requires %s between %d and %d.', $key, $minimum, $maximum));
            }
        }
        return $size;
    }

    /** The image's deployment profile validates the accelerator and device; this checks their shape.
     * @param array<string,mixed> $hardware
     * @return array{accelerator:string,device?:string,group?:int}
     */
    private static function hardwareEncoder(array $hardware): array
    {
        JsonDocument::keys($hardware, self::HARDWARE_KEYS, 'hardware encoder configuration');
        $encoder = ['accelerator' => self::string($hardware, 'accelerator')];
        if (array_key_exists('device', $hardware)) {
            $encoder['device'] = self::string($hardware, 'device');
        }
        if (array_key_exists('group', $hardware)) {
            if (!is_int($hardware['group']) || $hardware['group'] < 0) {
                throw new \InvalidArgumentException('The hardware encoder group must be a numeric group ID.');
            }
            $encoder['group'] = $hardware['group'];
        }
        return $encoder;
    }

    /**
     * The gateway renders each entry into its configuration, so each must be a plain address or range.
     *
     * @return list<string>
     */
    private static function frontProxies(mixed $proxies): array
    {
        if (!is_array($proxies) || !array_is_list($proxies) || !array_all($proxies, self::isAddressOrRange(...))) {
            throw new \InvalidArgumentException('listen.frontProxies must list IP addresses or CIDR ranges.');
        }

        return $proxies;
    }

    private static function isAddressOrRange(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);
        $bits = match (true) {
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 32,
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 128,
            default => null,
        };

        return $bits !== null && ($prefix === null || (preg_match('/^\d{1,3}$/D', $prefix) === 1 && (int) $prefix <= $bits));
    }

    /**
     * The edge network's IPv4 `subnet` and, unless `ipv6` is false, its IPv6 `subnet6`; each subnet left
     * out takes its default. An IPv4-only network refuses `subnet6`.
     *
     * @param array<string,mixed> $data
     */
    private static function edgeNetwork(array $data): EdgeNetwork
    {
        $network = array_key_exists('network', $data) ? self::map($data, 'network') : [];
        JsonDocument::keys($network, ['subnet', 'ipv6', 'subnet6'], 'network configuration');
        $ipv6 = array_key_exists('ipv6', $network) ? $network['ipv6'] : true;
        if (!is_bool($ipv6)) {
            throw new \InvalidArgumentException('network.ipv6 must be true or false.');
        }
        if (!$ipv6 && array_key_exists('subnet6', $network)) {
            throw new \InvalidArgumentException('An IPv4-only edge network takes no network.subnet6; remove it or set network.ipv6 to true.');
        }

        return EdgeNetwork::fromSubnets(
            array_key_exists('subnet', $network) ? self::string($network, 'subnet') : EdgeNetwork::DEFAULT_SUBNET,
            match (true) {
                !$ipv6 => null,
                array_key_exists('subnet6', $network) => self::string($network, 'subnet6'),
                default => EdgeNetwork::DEFAULT_SUBNET6,
            },
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['version' => 2, 'name' => $this->name, 'capability' => $this->capability, 'size' => $this->size, 'origin' => $this->origin,
            'listen' => ['address' => $this->listenAddress, 'port' => $this->listenPort, 'frontProxies' => $this->frontProxies],
            'network' => ['subnet' => $this->edge->ipv4->subnet, 'ipv6' => $this->edge->dualStack()]
                + ($this->edge->ipv6 === null ? [] : ['subnet6' => $this->edge->ipv6->subnet]),
            'images' => array_map(static fn(ImageSelection $image): string => $image->reference, $this->images),
            'environment' => $this->environment, 'resources' => $this->resources, 'volumes' => $this->volumes]
            + ($this->hardwareEncoder === null ? [] : ['hardware' => $this->hardwareEncoder]);
    }

    /** @param array<string,mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException('Missing or invalid ' . $key . '.');
        }
        return $value;
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function map(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException($key . ' must be an object.');
        }
        return $value;
    }
}
