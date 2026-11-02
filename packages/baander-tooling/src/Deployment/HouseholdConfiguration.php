<?php

declare(strict_types=1);

namespace Baander\Tooling\Deployment;

use Baander\Tooling\Configuration\{ImageSelection, JsonObject};

final readonly class HouseholdConfiguration
{
    public const array IMAGE_ROLES = ['application', 'worker', 'operator', 'postgres', 'gateway', 'redis'];
    public const array VOLUME_ROLES = ['media', 'storage', 'conversions', 'transcode_state', 'postgres', 'redis', 'secrets'];
    public const array RESOURCE_DEFAULTS = [
        'webMemoryMiB' => 1536, 'postgresMemoryMiB' => 512, 'redisMemoryMiB' => 384,
        'redisMaxmemoryMiB' => 192, 'gatewayMemoryMiB' => 64, 'operatorMemoryMiB' => 512,
        'hostHeadroomMiB' => 512,
    ];

    /** @param array<string,ImageSelection> $images
     * @param array<string,string> $environment
     * @param array<string,int> $resources
     * @param array<string,string> $volumes
     * @param list<int> $controlCpuSet
     * @param list<int> $executionCpuSet
     */
    private function __construct(
        public string $name,
        public string $profile,
        public string $origin,
        public string $listenAddress,
        public int $listenPort,
        public array $images,
        public array $environment,
        public array $resources,
        public array $volumes,
        public array $controlCpuSet,
        public array $executionCpuSet,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        JsonObject::keys($data, ['version', 'name', 'profile', 'origin', 'listen', 'images', 'environment', 'resources', 'volumes', 'controlCpuSet', 'executionCpuSet'], 'household configuration');
        if (($data['version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Unsupported household configuration version.');
        }
        $name = self::string($data, 'name');
        if (preg_match('/^[a-z][a-z0-9-]{2,40}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Deployment name requires 3–41 lowercase letters, digits or hyphens.');
        }
        $profile = self::string($data, 'profile');
        if (!in_array($profile, ['household', 'full'], true)) {
            throw new \InvalidArgumentException('Select the household or full deployment profile.');
        }
        $origin = self::string($data, 'origin');
        $url = parse_url($origin);
        if (!is_array($url) || !in_array($url['scheme'] ?? null, ['http', 'https'], true) || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || filter_var($origin, FILTER_VALIDATE_URL) === false || preg_match('/[\x00-\x20\x7f]/', $origin) === 1 || isset($url['query']) || isset($url['fragment']) || !in_array($url['path'] ?? '', ['', '/'], true)) {
            throw new \InvalidArgumentException('Origin must be an HTTP(S) origin without credentials, query or path.');
        }
        $listen = self::map($data, 'listen');
        JsonObject::keys($listen, ['address', 'port'], 'listen configuration');
        $address = self::string($listen, 'address');
        if (filter_var($address, FILTER_VALIDATE_IP) === false || !is_int($listen['port'] ?? null) || $listen['port'] < 1 || $listen['port'] > 65535) {
            throw new \InvalidArgumentException('Listen requires an IP address and TCP port.');
        }
        $images = self::map($data, 'images');
        JsonObject::keys($images, self::IMAGE_ROLES, 'deployment images');
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
        foreach (['APP_SECRET', 'STREAM_URL_HMAC_SECRET', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE', 'REDIS_PASSWORD'] as $key) {
            self::string($environment, $key);
        }
        foreach (['BAANDER_DEPLOYMENT_PROFILE', 'BAANDER_DEPLOYMENT_PROFILE_JSON', 'APP_ENV', 'APP_DEBUG', 'DATABASE_URL', 'REDIS_URL', 'MESSENGER_TRANSPORT_DSN', 'APP_URL', 'DEFAULT_URI', 'APP_DOMAIN', 'MEDIA_STORAGE_PATH', 'CONVERT_STORAGE_PATH', 'APP_HOME', 'OAUTH_PRIVATE_KEY_PATH', 'OAUTH_PUBLIC_KEY_PATH'] as $key) {
            if (array_key_exists($key, $environment)) {
                throw new \InvalidArgumentException('Deployment derives environment variable ' . $key . '.');
            }
        }
        $resources = $data['resources'] ?? [];
        if (!is_array($resources)) {
            throw new \InvalidArgumentException('Resources must be an object.');
        }
        JsonObject::keys($resources, array_keys(self::RESOURCE_DEFAULTS), 'deployment resources');
        $resources = array_replace(self::RESOURCE_DEFAULTS, $resources);
        foreach ($resources as $value) {
            if (!is_int($value) || $value < 1 || $value > 1048576) {
                throw new \InvalidArgumentException('Memory reservations must be positive integer MiB.');
            }
        }
        if ($resources['redisMaxmemoryMiB'] >= $resources['redisMemoryMiB']) {
            throw new \InvalidArgumentException('Redis needs memory beyond its dataset limit for persistence and overhead.');
        }
        $volumes = $data['volumes'] ?? [];
        if (!is_array($volumes)) {
            throw new \InvalidArgumentException('Volumes must be an object.');
        }
        JsonObject::keys($volumes, self::VOLUME_ROLES, 'deployment volumes');
        foreach (self::VOLUME_ROLES as $role) {
            $volumes[$role] ??= $name . '-' . str_replace('_', '-', $role);
            if (!is_string($volumes[$role]) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{1,127}$/D', $volumes[$role]) !== 1) {
                throw new \InvalidArgumentException('Persistent storage requires named Docker volumes.');
            }
        }
        if (count(array_unique($volumes)) !== count($volumes)) {
            throw new \InvalidArgumentException('Persistent volume roles must have distinct names.');
        }
        $control = self::cpuSet($data['controlCpuSet'] ?? [0]);
        $execution = self::cpuSet($data['executionCpuSet'] ?? [1]);
        if (array_intersect($control, $execution) !== []) {
            throw new \InvalidArgumentException('Control and execution CPU sets must be disjoint.');
        }
        return new self($name, $profile, rtrim($origin, '/'), $address, $listen['port'], $selections, $environment, $resources, $volumes, $control, $execution);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['version' => 1, 'name' => $this->name, 'profile' => $this->profile, 'origin' => $this->origin,
            'listen' => ['address' => $this->listenAddress, 'port' => $this->listenPort],
            'images' => array_map(static fn(ImageSelection $image): string => $image->reference, $this->images),
            'environment' => $this->environment, 'resources' => $this->resources, 'volumes' => $this->volumes,
            'controlCpuSet' => $this->controlCpuSet, 'executionCpuSet' => $this->executionCpuSet];
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

    /** @return non-empty-list<int> */
    private static function cpuSet(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new \InvalidArgumentException('CPU sets must be nonempty integer lists.');
        }
        foreach ($value as $cpu) {
            if (!is_int($cpu) || $cpu < 0) {
                throw new \InvalidArgumentException('CPU identifiers must be nonnegative integers.');
            }
        }
        if (count(array_unique($value)) !== count($value)) {
            throw new \InvalidArgumentException('CPU identifiers must be unique.');
        }
        return $value;
    }
}
