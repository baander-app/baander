<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\WorkerReadinessObservation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerReadinessObservationTest extends TestCase
{
    public function testReadinessRecognizesChildrenFromObservedImageHome(): void
    {
        $processes = self::processes('control');
        foreach ($processes as &$process) {
            $process['command'] = array_map(static fn (string $argument): string => str_replace('/var/www/html', '/opt/baander', $argument), $process['command']);
        }
        unset($process);
        self::assertCount(4, WorkerReadinessObservation::verifyProcesses($processes, self::lease(), 'control', new WorkerAutoscalingProfile(), '/opt/baander'));
    }

    public function testChildrenFromAnotherApplicationHomeDoNotConfirmReadiness(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('incomplete');
        WorkerReadinessObservation::verifyProcesses(self::processes('control'), self::lease(), 'control', new WorkerAutoscalingProfile(), '/opt/baander');
    }

    public function testCurrentGenerationsAreAcceptedForCompletePair(): void
    {
        foreach (['control', 'execution'] as $unit) {
            $roles = WorkerReadinessObservation::verifyProcesses(self::processes($unit), self::lease(), $unit, new WorkerAutoscalingProfile(), '/var/www/html');
            self::assertContains(2, $roles);
            self::assertCount($unit === 'control' ? 4 : 3, $roles);
        }
    }

    public function testMissingDuplicateForeignStaleAndReparentedChildrenAreRejected(): void
    {
        foreach (['missing', 'duplicate', 'foreign', 'epoch', 'unit', 'profile', 'transport', 'reparented', 'zombie', 'consumer-name'] as $kind) {
            $processes = self::processes('execution');
            match ($kind) {
                'missing' => array_pop($processes),
                'duplicate' => $processes[] = $processes[0],
                'foreign' => $processes[0]['environment'] = str_replace('readiness.baander.app', 'foreign.baander.app', $processes[0]['environment']),
                'epoch' => $processes[0]['environment'] = str_replace('LEASE_EPOCH=3', 'LEASE_EPOCH=2', $processes[0]['environment']),
                'unit' => $processes[0]['environment'] = str_replace('UNIT=execution', 'UNIT=control', $processes[0]['environment']),
                'profile' => $processes[0]['environment'] = str_replace('AUTOSCALING_PROFILE=', 'UNRECOGNIZED_PROFILE=', $processes[0]['environment']),
                'transport' => $processes[0]['command'][] = 'scheduler',
                'reparented' => $processes[0]['status'] = "PPid:\t22\nState:\tS (sleeping)\n",
                'zombie' => $processes[0]['status'] = "PPid:\t1\nState:\tZ (zombie)\n",
                'consumer-name' => $processes[0]['environment'] = str_replace('MESSENGER_CONSUMER_NAME=worker-', 'MESSENGER_CONSUMER_NAME=foreign-', $processes[0]['environment']),
            };
            $rejected = false;
            try {
                WorkerReadinessObservation::verifyProcesses($processes, self::lease(), 'execution', new WorkerAutoscalingProfile(), '/var/www/html');
            } catch (RuntimeException) {
                $rejected = true;
            }
            self::assertTrue($rejected, $kind . ' readiness accepted');
        }
    }

    /** @return list<array{command:list<string>,status:string,environment:string}> */
    private static function processes(string $unit): array
    {
        $commands = $unit === 'control' ? ['relay' => ['app:outbox:consume'], 'scheduler' => ['/var/www/html/bin/worker-scheduler.php'],
            'media' => ['/var/www/html/bin/worker-media.php'], 'autoscaler' => ['/var/www/html/bin/worker-autoscaler.php']]
            : ['consumer-0' => ['messenger:consume', 'async'], 'scheduled-consumer' => ['messenger:consume', 'scheduler'], 'native' => ['/var/www/html/bin/worker-native.php']];
        $processes = [];
        foreach ($commands as $role => $command) {
            $lease = self::lease();
            $environment = ['BAANDER_WORKER_NAMESPACE=' . $lease->namespace, 'BAANDER_WORKER_BOOT_ID=' . $lease->bootId,
                'BAANDER_WORKER_LEASE_EPOCH=3', 'BAANDER_WORKER_UNIT=' . $unit, 'BAANDER_WORKER_ID=' . $role,
                'BAANDER_WORKER_GENERATION=2', 'BAANDER_WORKER_AUTOSCALING_PROFILE=' . (new WorkerAutoscalingProfile())->toJson()];
            if ($role !== 'native') {
                $environment[] = 'MESSENGER_CONSUMER_NAME=worker-' . substr(hash('sha256', $lease->namespace), 0, 16) . '-' . $lease->bootId . '-' . $role . '-2';
            }
            $processes[] = ['command' => ['/usr/local/bin/php', ...$command], 'status' => "PPid:\t1\nState:\tS (sleeping)\n", 'environment' => implode("\0", $environment) . "\0"];
        }
        return $processes;
    }

    private static function lease(): DeploymentLease
    {
        return new DeploymentLease('readiness.baander.app', str_repeat('a', 32), 3);
    }
}
