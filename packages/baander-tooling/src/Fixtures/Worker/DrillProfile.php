<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use InvalidArgumentException;

/**
 * The full deployment profile a worker drill runs: the fixture's three video and one audio
 * slot unless BAANDER_TEST_DEPLOYMENT_PROFILE names another profile file. Web, both worker
 * units and the drill's clients take their job counts from it.
 */
final readonly class DrillProfile
{
    public const string ENVIRONMENT = 'BAANDER_TEST_DEPLOYMENT_PROFILE';
    public const string DEFAULT_PATH = 'tests/Fixtures/Worker/deployment-profile.json';
    private const array FIELDS = ['webWorkers', 'doctrinePoolSlots', 'ordinaryConsumerLimit', 'sharedCopySlots', 'videoEncoderSlots', 'audioEncoderSlots', 'identifier', 'hardware'];

    /** @param array<string,mixed> $value */
    private function __construct(
        public string $path,
        public array $value,
        public int $videoSlots,
        public int $audioSlots,
        public ?string $device,
    ) {}

    /** @param array<string,string> $environment */
    public static function resolve(string $root, array $environment): self
    {
        $path = ($environment[self::ENVIRONMENT] ?? '') ?: self::DEFAULT_PATH;
        if (!str_starts_with($path, '/')) {
            $path = $root . '/' . $path;
        }
        if (!is_file($path)) {
            throw new InvalidArgumentException(self::ENVIRONMENT . ' must name a deployment profile file', 2);
        }
        return self::fromArray($path, OperatorConfiguration::json($path));
    }

    /** @param array<mixed> $value */
    public static function fromArray(string $path, array $value): self
    {
        if (array_keys($value) !== self::FIELDS || $value['identifier'] !== 'full') {
            throw new InvalidArgumentException('A drill runs a full deployment profile with exactly the serialized profile fields in order', 2);
        }
        foreach (self::FIELDS as $field) {
            if (!in_array($field, ['identifier', 'hardware'], true) && (!is_int($value[$field]) || $value[$field] < 0)) {
                throw new InvalidArgumentException('Deployment profile field ' . $field . ' requires a non-negative integer', 2);
            }
        }
        $hardware = $value['hardware'];
        if ($hardware !== null && (!is_array($hardware) || array_keys($hardware) !== ['accelerator', 'device']
            || !is_string($hardware['accelerator']) || !(is_string($hardware['device']) || $hardware['device'] === null))) {
            throw new InvalidArgumentException('Deployment profile hardware requires an accelerator and a device', 2);
        }
        /** @var array<string,mixed> $value */
        return new self($path, $value, $value['videoEncoderSlots'], $value['audioEncoderSlots'], $hardware['device'] ?? null);
    }

    /** The profile as the application serializes it, so worker readiness sees identical environments. */
    public function json(): string
    {
        return json_encode($this->value, JSON_THROW_ON_ERROR);
    }

    /**
     * The group that owns the configured device, which worker creation adds to the execution unit.
     *
     * @param (\Closure(string): (int|false))|null $groupOf
     */
    public function deviceGroup(?\Closure $groupOf = null): ?int
    {
        $device = $this->device;
        if ($device === null) {
            return null;
        }
        $group = ($groupOf ?? static fn(string $path): int|false => @filegroup($path))($device);
        if ($group === false) {
            throw new InvalidArgumentException('Configured hardware device ' . $device . ' is not present on this host', 2);
        }
        return $group;
    }
}
