<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker\Autoscaling;

use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\WorkerLaunchIdentity;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class WorkerAdmissionContext
{
    public function __construct(public AdmissionLedger $ledger, public WorkerLaunchIdentity $identity)
    {
    }

    public static function fromEnvironment(): ?self
    {
        $id = getenv('BAANDER_WORKER_ID');
        if (!is_string($id) || !str_starts_with($id, 'consumer')) {
            return null;
        }
        if (preg_match('/^consumer-[0-3]$/D', $id) !== 1 || getenv('BAANDER_WORKER_UNIT') !== 'execution') {
            throw new \RuntimeException('Ordinary receive requires an admitted execution slot.');
        }
        $namespace = self::environment('BAANDER_WORKER_NAMESPACE');
        $boot = self::environment('BAANDER_WORKER_BOOT_ID');
        $generation = self::positiveInteger('BAANDER_WORKER_GENERATION');
        $lease = new DeploymentLease($namespace, $boot, self::positiveInteger('BAANDER_WORKER_LEASE_EPOCH'));
        $profile = WorkerAutoscalingProfile::fromJson(self::environment('BAANDER_WORKER_AUTOSCALING_PROFILE'));
        $ledger = new AdmissionLedger(self::environment('BAANDER_WORKER_IPC_DIRECTORY'), $lease, $profile);
        return new self($ledger, new WorkerLaunchIdentity($namespace, $boot, $id, $generation));
    }

    private static function environment(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException('Ordinary receive requires complete inherited admission.');
        }
        return $value;
    }

    private static function positiveInteger(string $name): int
    {
        $value = self::environment($name);
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 1 || (string) $integer !== $value) {
            throw new \RuntimeException('Ordinary receive requires bounded inherited identity.');
        }
        return $integer;
    }
}
