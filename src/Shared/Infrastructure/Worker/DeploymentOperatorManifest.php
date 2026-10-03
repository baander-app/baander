<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\WorkerRuntimeConfiguration;
use InvalidArgumentException;
use JsonException;
use stdClass;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Versioned operator input; worker argv and its container layout are fixed. */
#[Exclude]
final readonly class DeploymentOperatorManifest
{
    private const array STRING_FIELDS = ['namespace', 'bootId', 'daemonId', 'imageId', 'network', 'dockerBinary', 'dockerEndpoint'];
    private const array INTEGER_FIELDS = ['version', 'memoryMiB', 'managementMiB', 'consumerMiB', 'relayMiB', 'schedulerMiB', 'scheduledConsoleMiB', 'nanoCpus', 'pidsLimit'];
    private const int MIB = 1024 * 1024;

    private function __construct(
        public WorkerRuntimeConfiguration $configuration,
        public string $daemonId,
        public string $imageId,
        public string $network,
        public string $dockerBinary,
        public string $dockerEndpoint,
        public int $nanoCpus,
        public int $pidsLimit,
    ) {
        if (!str_starts_with($dockerBinary, '/') || strlen($dockerBinary) > 4096
            || preg_match('/[\x00-\x1f\x7f]/', $dockerBinary) !== 0
            || strlen($dockerEndpoint) > 4096
            || preg_match('~\Aunix:///[^\x00-\x20\x7f]+\z~D', $dockerEndpoint) !== 1
        ) {
            throw new InvalidArgumentException('Operator manifest requires an absolute Docker binary and local Unix endpoint.');
        }
        // Validate Docker isolation and immutable image before any operator action.
        $this->recipe(new DeploymentRuntimeEnvironment());
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > 8192) {
            throw new InvalidArgumentException('Operator manifest exceeds 8192 bytes.');
        }
        try {
            $decoded = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Operator manifest must be a bounded JSON object.');
        }
        if (!$decoded instanceof stdClass) {
            throw new InvalidArgumentException('Operator manifest must be a JSON object.');
        }
        $fields = get_object_vars($decoded);
        $expected = [...self::STRING_FIELDS, ...self::INTEGER_FIELDS];
        if (array_diff(array_keys($fields), $expected) !== [] || array_diff($expected, array_keys($fields)) !== []) {
            throw new InvalidArgumentException('Operator manifest has unknown or missing fields.');
        }
        if (self::integer($fields, 'version') !== 1) {
            throw new InvalidArgumentException('Operator manifest version must be 1.');
        }
        $configuration = new WorkerRuntimeConfiguration(
            self::string($fields, 'namespace'), self::string($fields, 'bootId'),
            self::bytes($fields, 'memoryMiB'), self::bytes($fields, 'managementMiB'),
            self::bytes($fields, 'consumerMiB'), self::bytes($fields, 'relayMiB'),
            '/tmp/baander-worker-locks', self::bytes($fields, 'scheduledConsoleMiB'),
            self::bytes($fields, 'schedulerMiB'),
        );
        return new self(
            $configuration, self::string($fields, 'daemonId'), self::string($fields, 'imageId'),
            self::string($fields, 'network'), self::string($fields, 'dockerBinary'), self::string($fields, 'dockerEndpoint'),
            self::integer($fields, 'nanoCpus'), self::integer($fields, 'pidsLimit'),
        );
    }

    public function recipe(DeploymentRuntimeEnvironment $environment): DeploymentContainerRecipe
    {
        $configuration = $this->configuration;
        return new DeploymentContainerRecipe(
            $configuration->namespace, $configuration->bootId, $this->daemonId, $this->imageId,
            [
                '/usr/local/bin/php', '-d', 'memory_limit=' . intdiv($configuration->managementReservationBytes, self::MIB) . 'M',
                '/var/www/html/bin/console', 'app:worker', '--no-interaction',
                '--deployment=' . $configuration->namespace, '--boot-id=' . $configuration->bootId,
                '--memory-mib=' . intdiv($configuration->memoryLimitBytes, self::MIB),
                '--management-mib=' . intdiv($configuration->managementReservationBytes, self::MIB),
                '--consumer-mib=' . intdiv($configuration->consumerReservationBytes, self::MIB),
                '--relay-mib=' . intdiv($configuration->relayReservationBytes, self::MIB),
                '--scheduler-mib=' . intdiv($configuration->schedulerReservationBytes, self::MIB),
                '--scheduled-console-mib=' . intdiv($configuration->scheduledConsoleReservationBytes, self::MIB),
                '--lock-dir=' . $configuration->lockDirectory,
            ],
            $this->network, $configuration->memoryLimitBytes, $this->nanoCpus, $this->pidsLimit, $environment,
        );
    }

    /** @param array<string,mixed> $fields */
    private static function string(array $fields, string $key): string
    {
        $value = $fields[$key];
        if (!is_string($value)) {
            throw new InvalidArgumentException('Operator manifest string fields require JSON strings.');
        }
        return $value;
    }

    /** @param array<string,mixed> $fields */
    private static function integer(array $fields, string $key): int
    {
        $value = $fields[$key];
        if (!is_int($value)) {
            throw new InvalidArgumentException('Operator manifest integer fields require JSON integers.');
        }
        return $value;
    }

    /** @param array<string,mixed> $fields */
    private static function bytes(array $fields, string $key): int
    {
        $value = self::integer($fields, $key);
        if ($value < 0 || $value > 1048576) {
            throw new InvalidArgumentException('Operator memory fields require MiB values between 0 and 1048576.');
        }
        return $value * self::MIB;
    }
}
