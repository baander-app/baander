<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Identity and admission inputs of one worker unit; the slots, not container limits, bound its media work. */
#[Exclude]
final readonly class WorkerRuntimeConfiguration
{
    /** Local helper processes an ordinary or scheduled consumer runs at once, such as one probe or lookup. */
    public const int CONSUMER_DESCENDANTS = 1;

    /**
     * Processes the supervisor may own at once: its fixed roles, the media manager with one
     * media child per slot, consumers with their local helpers, any scheduled console child,
     * and the lease helper.
     */
    public int $childProcessCeiling;

    public function __construct(
        public string $namespace,
        public string $bootId,
        public string $unit,
        public int $scheduledConsoleReservationBytes,
        public string $lockDirectory,
        public string $ipcDirectory,
        public DeploymentProfile $deploymentProfile,
        public WorkerAutoscalingProfile $autoscaling = new WorkerAutoscalingProfile(),
    ) {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $namespace) !== 1
            || preg_match('/\A[0-9a-f]{32}\z/D', $bootId) !== 1
            || !in_array($unit, ['control', 'execution'], true)
            || $autoscaling->maximumConcurrency > $deploymentProfile->ordinaryConsumerLimit
            || $scheduledConsoleReservationBytes < 0 || $scheduledConsoleReservationBytes > 1024 ** 4
            || ($scheduledConsoleReservationBytes !== 0 && ($unit !== 'execution' || $scheduledConsoleReservationBytes < $deploymentProfile->phpProcessMemoryMiB() * 1024 * 1024))
            || !$this->validPath($lockDirectory) || !$this->validPath($ipcDirectory)
        ) {
            throw new \InvalidArgumentException('Worker requires a bounded identity, absolute private directories and an ordinary concurrency within the profile. Scheduled console requires one PHP process reservation in execution.');
        }
        $this->childProcessCeiling = self::childProcessCeilingFor($unit, $deploymentProfile, $autoscaling->maximumConcurrency, $scheduledConsoleReservationBytes > 0);
    }

    /**
     * Processes a unit's supervisor may own at once, including the lease helper. The control
     * unit's ceiling follows the media slots; the execution unit's follows its ordinary
     * concurrency and whether scheduled console execution is enabled.
     */
    public static function childProcessCeilingFor(string $unit, DeploymentProfile $deploymentProfile, int $maximumConcurrency, bool $scheduledConsole): int
    {
        return 1 + ($unit === 'control'
            // Relay, scheduler and autoscaler, then the media manager and its media children.
            ? 3 + 1 + $deploymentProfile->mediaChildSlots()
            // Ordinary consumers and the scheduled consumer, then its console child.
            : ($maximumConcurrency + 1) * (1 + self::CONSUMER_DESCENDANTS) + ($scheduledConsole ? 1 : 0));
    }

    private function validPath(string $path): bool
    {
        return str_starts_with($path, '/') && strlen($path) <= 4000 && !str_contains($path, "\0") && !in_array('..', explode('/', $path), true);
    }
}
