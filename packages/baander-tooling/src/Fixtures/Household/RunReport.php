<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Household;

use Baander\Tooling\Deployment\Household;

/** A single-run receipt is evidence, never a hardware qualification claim. */
final class RunReport
{
    /** @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    public static function evaluate(RunContract $contract, string $samplesPath, array $evidence): array
    {
        $declaration = $contract->toArray();
        $failures = [];
        $fail = static function (string $reason) use (&$failures): void {
            $failures[$reason] = true;
        };
        $measured = ['samples' => 0, 'totalHostMemBytes' => null, 'minimumHostAvailableBytes' => null,
            'peakHostUsedBytes' => 0, 'peakCgroupCurrentBytes' => 0, 'cgroups' => [], 'observerPeakBytes' => 0,
            'nativeProcessPeak' => 0, 'nativeThreadPeak' => 0, 'deploymentOomKills' => 0, 'swapGrowthBytes' => 0, 'peakSwapGrowthBytes' => 0,
            'cgroupSwapGrowthBytes' => 0, 'peakCgroupSwapGrowthBytes' => 0,
            'swapInPages' => 0, 'swapOutPages' => 0, 'memoryPressureAvg10Peak' => ['some' => 0.0, 'full' => 0.0]];
        $first = null;
        $last = null;
        $nextSequence = 0;
        $initialSwap = null;
        $initialCgroupSwap = [];
        $initialOomKills = [];
        $oomKills = [];
        $initialVmstat = null;
        $seenRoles = [];
        $stream = @fopen($samplesPath, 'rb');
        if ($stream === false) {
            $fail('Measurement samples are missing.');
        } else {
            try {
                while (($line = fgets($stream, HostSampler::MAX_LINE_BYTES + 1)) !== false) {
                    if (!str_ends_with($line, "\n") || strlen($line) > HostSampler::MAX_LINE_BYTES) {
                        $fail('Measurement contains an oversized or incomplete record.');
                        break;
                    }
                    try {
                        $sample = json_decode($line, true, 24, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        $fail('Measurement contains invalid JSON.');
                        continue;
                    }
                    if (!is_array($sample) || ($sample['version'] ?? null) !== 1) {
                        $fail('Measurement sample schema is invalid.');
                        continue;
                    }
                    ++$measured['samples'];
                    if (($sample['sequence'] ?? null) !== $nextSequence) {
                        $fail('Missing or repeated sample sequence.');
                    }
                    $nextSequence = is_int($sample['sequence'] ?? null) ? $sample['sequence'] + 1 : $nextSequence + 1;
                    $time = $sample['monotonic'] ?? null;
                    if (!self::number($time) || $time < $declaration['startedMonotonic'] || ($last !== null && ($time <= $last || $time - $last > $declaration['maxSampleGapSeconds']))) {
                        $fail('Measurement timestamps violate the declared sampling interval.');
                    } else {
                        $first ??= $time;
                        $last = $time;
                    }
                    if (!self::number($sample['captureWindowSeconds'] ?? null) || $sample['captureWindowSeconds'] < 0 || $sample['captureWindowSeconds'] > $declaration['maxSampleGapSeconds']) {
                        $fail('Capture window exceeds the synchronized sampling bound.');
                    }
                    if (($sample['missing'] ?? null) !== []) {
                        $fail('Required telemetry is missing.');
                    }
                    $host = $sample['host'] ?? [];
                    $hostValid = true;
                    foreach (['memTotalBytes', 'memAvailableBytes', 'swapTotalBytes', 'swapFreeBytes'] as $field) {
                        if (!self::number($host[$field] ?? null) || $host[$field] < 0) {
                            $hostValid = false;
                        }
                    }
                    if (!$hostValid || $host['memTotalBytes'] <= 0 || $host['memAvailableBytes'] > $host['memTotalBytes'] || $host['swapFreeBytes'] > $host['swapTotalBytes']) {
                        $fail('Host memory accounting is incomplete or invalid.');
                    } else {
                        if ($measured['totalHostMemBytes'] !== null && $measured['totalHostMemBytes'] !== $host['memTotalBytes']) {
                            $fail('Host memory constraint changed during the run.');
                        }
                        $measured['totalHostMemBytes'] = $host['memTotalBytes'];
                        $measured['minimumHostAvailableBytes'] = min($measured['minimumHostAvailableBytes'] ?? $host['memAvailableBytes'], $host['memAvailableBytes']);
                        $measured['peakHostUsedBytes'] = max($measured['peakHostUsedBytes'], $host['memTotalBytes'] - $host['memAvailableBytes']);
                        $swap = $host['swapTotalBytes'] - $host['swapFreeBytes'];
                        $initialSwap ??= $swap;
                        $measured['swapGrowthBytes'] = max(0, $swap - $initialSwap);
                        $measured['peakSwapGrowthBytes'] = max($measured['peakSwapGrowthBytes'], $measured['swapGrowthBytes']);
                    }
                    $vmstat = $host['vmstat'] ?? [];
                    if (!self::counter($vmstat['pswpin'] ?? null) || !self::counter($vmstat['pswpout'] ?? null)) {
                        $fail('Host swap counters are missing.');
                    } else {
                        $initialVmstat ??= $vmstat;
                        $measured['swapInPages'] = $vmstat['pswpin'] - $initialVmstat['pswpin'];
                        $measured['swapOutPages'] = $vmstat['pswpout'] - $initialVmstat['pswpout'];
                        if ($measured['swapInPages'] < 0 || $measured['swapOutPages'] < 0) {
                            $fail('Host swap counters decreased.');
                        }
                    }
                    foreach (['some', 'full'] as $pressure) {
                        $psi = $host['memoryPressure'][$pressure] ?? [];
                        foreach (['avg10', 'avg60', 'avg300', 'total'] as $field) {
                            if (!self::number($psi[$field] ?? null) || $psi[$field] < 0) {
                                $fail('Host memory pressure telemetry is missing.');
                            }
                        }
                        $measured['memoryPressureAvg10Peak'][$pressure] = max($measured['memoryPressureAvg10Peak'][$pressure], self::number($psi['avg10'] ?? null) ? $psi['avg10'] : 0);
                    }
                    $groups = $sample['cgroups'] ?? null;
                    $frameRoles = [];
                    $frameBytes = 0;
                    $frameSwapGrowth = 0;
                    $paths = [];
                    if (!is_array($groups) || count($groups) > 512) {
                        $fail('Cgroup accounting is missing or exceeds its bound.');
                        $groups = [];
                    }
                    foreach ($groups as $identity => $group) {
                        if (!is_string($identity) || !is_array($group) || !is_string($group['path'] ?? null) || !is_array($group['roles'] ?? null)) {
                            $fail('Cgroup identity or role accounting is invalid.');
                            continue;
                        }
                        $path = rtrim($group['path'], '/');
                        foreach ($paths as $other) {
                            if ($path === $other || $path === '' || $other === '' || str_starts_with($path, $other . '/') || str_starts_with($other, $path . '/')) {
                                $fail('Cgroup accounting overlaps or repeats a group.');
                            }
                        }
                        $paths[] = $path;
                        foreach (['memoryCurrentBytes', 'memoryPeakBytes', 'swapCurrentBytes', 'swapPeakBytes', 'pidsCurrent'] as $field) {
                            if (!self::counter($group[$field] ?? null)) {
                                $fail('Cgroup counters are incomplete.');
                            }
                        }
                        foreach (['memoryMaxBytes', 'swapMaxBytes', 'pidsMax'] as $field) {
                            if (!self::counter($group[$field] ?? null) && ($group[$field] ?? null) !== 'max') {
                                $fail('Cgroup configured limits are missing.');
                            }
                        }
                        $events = $group['memoryEvents'] ?? [];
                        if (!self::counter($events['oom'] ?? null) || !self::counter($events['oom_kill'] ?? null)) {
                            $fail('Cgroup OOM event evidence is missing.');
                        } else {
                            if ($events['oom'] > 0 || $events['oom_kill'] > 0) {
                                $fail('OOM activity was observed.');
                            }
                            if (!in_array('observer', $group['roles'], true)) {
                                // Counters are cgroup lifetimes: only kills after the first sample belong to this run.
                                $initialOomKills[$identity] ??= $measured['samples'] === 1 ? $events['oom_kill'] : 0;
                                $oomKills[$identity] = max($oomKills[$identity] ?? 0, $events['oom_kill'] - $initialOomKills[$identity]);
                            }
                        }
                        $current = self::counter($group['memoryCurrentBytes'] ?? null) ? $group['memoryCurrentBytes'] : 0;
                        $peak = self::counter($group['memoryPeakBytes'] ?? null) ? $group['memoryPeakBytes'] : 0;
                        $frameBytes += $current;
                        if (!isset($measured['cgroups'][$identity]) && count($measured['cgroups']) >= 4096) {
                            $fail('Cgroup history exceeds its bounded capacity.');
                            continue;
                        }
                        $swapCurrent = self::counter($group['swapCurrentBytes'] ?? null) ? $group['swapCurrentBytes'] : 0;
                        $initialCgroupSwap[$identity] ??= $measured['samples'] === 1 ? $swapCurrent : 0;
                        $swapGrowth = max(0, $swapCurrent - $initialCgroupSwap[$identity]);
                        $frameSwapGrowth += $swapGrowth;
                        $measured['cgroups'][$identity] = ['path' => $group['path'], 'roles' => $group['roles'],
                            'peakCurrentBytes' => max($measured['cgroups'][$identity]['peakCurrentBytes'] ?? 0, $current),
                            'kernelPeakBytes' => max($measured['cgroups'][$identity]['kernelPeakBytes'] ?? 0, $peak),
                            'memoryMaxBytes' => $group['memoryMaxBytes'] ?? null,
                            'peakSwapBytes' => max($measured['cgroups'][$identity]['peakSwapBytes'] ?? 0, self::counter($group['swapCurrentBytes'] ?? null) ? $group['swapCurrentBytes'] : 0),
                            'kernelSwapPeakBytes' => max($measured['cgroups'][$identity]['kernelSwapPeakBytes'] ?? 0, self::counter($group['swapPeakBytes'] ?? null) ? $group['swapPeakBytes'] : 0),
                            'swapMaxBytes' => $group['swapMaxBytes'] ?? null,
                            'initialSwapBytes' => $initialCgroupSwap[$identity], 'lastSwapBytes' => $swapCurrent, 'swapGrowthBytes' => $swapGrowth,
                            'peakPids' => max($measured['cgroups'][$identity]['peakPids'] ?? 0, self::counter($group['pidsCurrent'] ?? null) ? $group['pidsCurrent'] : 0),
                            'pidsMax' => $group['pidsMax'] ?? null, 'memoryEvents' => $events];
                        foreach ($group['roles'] as $role) {
                            if (!is_string($role) || isset($frameRoles[$role])) {
                                $fail('A service role maps to multiple cgroups.');
                                continue;
                            }
                            $frameRoles[$role] = true;
                            if (!isset($seenRoles[$role]) && count($seenRoles) >= 1024) {
                                $fail('Service role history exceeds its bounded capacity.');
                                continue;
                            }
                            $seenRoles[$role] = true;
                            if ($role === 'observer') {
                                $measured['observerPeakBytes'] = max($measured['observerPeakBytes'], $current);
                            }
                            $limit = $declaration['configuredLimits'][$role]['memoryMaxBytes'] ?? null;
                            if ($limit !== null && $limit !== ($group['memoryMaxBytes'] ?? null)) {
                                $fail('Effective cgroup limits differ from the declared configuration.');
                            }
                        }
                    }
                    $measured['cgroupSwapGrowthBytes'] = $frameSwapGrowth;
                    $measured['peakCgroupSwapGrowthBytes'] = max($measured['peakCgroupSwapGrowthBytes'], $frameSwapGrowth);
                    $measured['peakCgroupCurrentBytes'] = max($measured['peakCgroupCurrentBytes'], $frameBytes);
                    if ($contract->requiresPlayback() && array_diff($declaration['requiredRoles'], array_keys($frameRoles)) !== []) {
                        $fail('Required services are absent during playback.');
                    }
                    if (!is_array($sample['processes'] ?? null) || count($sample['processes']) > 4096 || !self::counter($sample['nativeProcessCount'] ?? null) || !self::counter($sample['nativeThreadCount'] ?? null)) {
                        $fail('Process or native concurrency evidence is missing.');
                    } else {
                        if ($groups !== [] && $sample['processes'] === []) {
                            $fail('Mapped service process evidence is empty.');
                        }
                        $nativeProcesses = 0;
                        $nativeThreads = 0;
                        $processIds = [];
                        foreach ($sample['processes'] as $identity => $process) {
                            if (!is_array($process) || !self::counter($process['pid'] ?? null) || $process['pid'] === 0 || !self::counter($process['threads'] ?? null) || !is_bool($process['native'] ?? null)) {
                                $fail('Process observation is incomplete.');
                                continue;
                            }
                            if (!is_string($identity) || preg_match('/^' . $process['pid'] . ':[0-9]+$/D', $identity) !== 1 || isset($processIds[$process['pid']])) {
                                $fail('Process identities repeat or are inconsistent.');
                            }
                            $processIds[$process['pid']] = true;
                            if ($process['native']) {
                                ++$nativeProcesses;
                                $nativeThreads += $process['threads'];
                            }
                        }
                        if ($nativeProcesses !== $sample['nativeProcessCount'] || $nativeThreads !== $sample['nativeThreadCount']) {
                            $fail('Native concurrency counters disagree with process observations.');
                        }
                        $measured['nativeProcessPeak'] = max($measured['nativeProcessPeak'], $sample['nativeProcessCount']);
                        $measured['nativeThreadPeak'] = max($measured['nativeThreadPeak'], $sample['nativeThreadCount']);
                    }
                }
                if (!feof($stream)) {
                    $fail('Measurement stream did not finish cleanly.');
                }
            } finally {
                fclose($stream);
            }
        }
        $measured['deploymentOomKills'] = array_sum($oomKills);
        if ($first === null || $last === null || $first - $declaration['startedMonotonic'] > $declaration['maxSampleGapSeconds']
            || $last - $declaration['startedMonotonic'] < $declaration['durationSeconds']) {
            $fail('Samples do not cover the declared run duration.');
        }
        if (array_diff($declaration['requiredRoles'], array_keys($seenRoles)) !== []) {
            $fail('Required service accounting is incomplete.');
        }
        if (($evidence['contractFingerprint'] ?? null) !== $contract->fingerprint()) {
            $fail('Evidence does not match the declared run contract.');
        }
        $completed = $evidence['completedMonotonic'] ?? null;
        if (!self::number($completed) || $completed < ($last ?? INF) || $completed - ($last ?? 0) > $declaration['maxSampleGapSeconds'] || $completed - $declaration['startedMonotonic'] < $declaration['durationSeconds']) {
            $fail('Run completion time is missing or inconsistent.');
        }
        if ($contract->requiresPlayback()) {
            $playback = $evidence['playback'] ?? null;
            if (!is_array($playback) || count($playback) !== $contract->players()) {
                $fail('Playback progress records do not match the declared player count.');
            } else {
                $ids = [];
                foreach ($playback as $record) {
                    if (!is_array($record) || !is_string($record['id'] ?? null) || isset($ids[$record['id']])
                        || !self::number($record['observedSeconds'] ?? null) || $record['observedSeconds'] < $declaration['durationSeconds']
                        || !self::number($record['progressSeconds'] ?? null) || $record['progressSeconds'] < $declaration['durationSeconds'] - $declaration['maxSampleGapSeconds']
                        || !self::counter($record['progressObservations'] ?? null) || $record['progressObservations'] < max(2, (int) ceil($declaration['durationSeconds'] / $declaration['maxSampleGapSeconds'])) || ($record['stalls'] ?? null) !== 0) {
                        $fail('Playback progress or interruption evidence does not pass.');
                    }
                    if (is_string($record['id'] ?? null)) {
                        $ids[$record['id']] = true;
                    }
                }
            }
        }
        foreach (['scan', 'metadata'] as $name) {
            $queue = $evidence['queues'][$name] ?? [];
            if (($queue['completed'] ?? null) !== true || ($queue['remaining'] ?? null) !== 0 || ($queue['failed'] ?? null) !== 0 || ($queue['lost'] ?? null) !== 0
                || !self::counter($queue['retried'] ?? null) || !self::counter($queue['maxAttempts'] ?? null)
                || !self::number($queue['completedAtSeconds'] ?? null) || $queue['completedAtSeconds'] > $declaration['maintenanceDeadlineSeconds']
                || $queue['completedAtSeconds'] < 0 || !self::number($completed) || $queue['completedAtSeconds'] > $completed - $declaration['startedMonotonic']) {
                $fail('Maintenance completion evidence does not pass.');
            }
        }
        if (($evidence['nativeAudit']['complete'] ?? null) !== true) {
            $fail('Native launch audit is incomplete.');
        }
        if (($evidence['nativeAudit']['forbiddenLaunches'] ?? null) !== 0) {
            $fail('Prohibited native launch evidence does not pass.');
        }
        if (($evidence['nativeAudit']['capability'] ?? null) !== $declaration['capability']) {
            $fail('Native launch audit applied another capability than the run contract.');
        }
        $oomKilledContainers = $evidence['oomKilledContainers'] ?? null;
        if (!is_array($oomKilledContainers) || !array_is_list($oomKilledContainers)) {
            $fail('Container OOM event evidence is missing.');
            $oomKilledContainers = [];
        } elseif ($oomKilledContainers !== []) {
            $fail('OOM activity was observed.');
        }
        if ($declaration['scenario'] === 'recovery' && ($evidence['recovery']['recovered'] ?? null) !== true) {
            $fail('The deployment did not recover after PostgreSQL restarted.');
        }
        if (($evidence['unexpectedExits'] ?? null) !== []) {
            $fail('Unexpected process exit evidence does not pass.');
        }
        $operatorAudits = [];
        foreach (['workload', 'shutdown'] as $phase) {
            $input = $evidence['operatorMeasurements'][$phase] ?? null;
            if (!is_array($input) || !is_array($input['receipts'] ?? null) || !is_array($input['context'] ?? null)
                || !is_array($input['requiredActions'] ?? null) || !self::number($input['from'] ?? null) || !self::number($input['until'] ?? null)
                || ($input['boundaryVerified'] ?? null) !== true) {
                $operatorAudits[$phase] = ['complete' => false, 'errors' => ['Operator measurement boundary or receipts are missing.']];
            } else {
                $required = $phase === 'shutdown' ? ['status' => 1, 'recover' => 1]
                    : ($declaration['scenario'] === 'cold-startup' ? ['prepare' => 1, 'ipc-initialize' => 1, 'create' => 1, 'start' => 1, 'readiness' => 1] : ['readiness' => 2]);
                $operatorAudits[$phase] = self::operatorAudit($input['receipts'], $input['context'], $required, (float) $input['from'], (float) $input['until']);
                if (($input['context']['namespace'] ?? null) !== ($declaration['workerNamespace'] ?? null)
                    || ($input['context']['operatorImage'] ?? null) !== ($declaration['operatorImageId'] ?? null)
                    || ($input['context']['workerImage'] ?? null) !== ($declaration['workerImageId'] ?? null)) {
                    $operatorAudits[$phase]['complete'] = false;
                    $operatorAudits[$phase]['errors'][] = 'Operator declaration differs from the run contract.';
                }
                if ($phase === 'workload' && ((float) $input['from'] !== (float) $declaration['startedMonotonic'] || (float) $input['until'] !== (float) $completed)) {
                    $operatorAudits[$phase]['complete'] = false;
                    $operatorAudits[$phase]['errors'][] = 'Operator workload window differs from the measured run.';
                }
            }
            if (!$operatorAudits[$phase]['complete']) {
                $fail('Operator ' . $phase . ' measurement is incomplete or failed.');
            }
        }
        $helpers = $evidence['helperMeasurements'] ?? null;
        $helperAudit = ['complete' => false, 'errors' => ['Helper measurement boundary or receipts are missing.']];
        if (is_array($helpers) && ($helpers['boundaryVerified'] ?? null) === true
            && is_array($helpers['receipts'] ?? null) && is_array($helpers['context'] ?? null)
            && self::number($helpers['from'] ?? null) && self::number($helpers['until'] ?? null)) {
            $roles = Household::helperRoles($declaration['scenario'] === 'cold-startup');
            $required = array_fill_keys(array_keys($roles), 1);
            $helperAudit = self::operatorAudit($helpers['receipts'], $helpers['context'], $required, (float) $helpers['from'], (float) $helpers['until']);
            $images = ['application' => $declaration['imageId'], 'worker' => $declaration['workerImageId'] ?? null, 'operator' => $declaration['operatorImageId'] ?? null];
            foreach (Household::helperRoles(true) + Household::helperRoles(false) as $action => $role) {
                if (($helpers['context']['actionImages'][$action] ?? null) !== $images[$role]) {
                    $helperAudit['complete'] = false;
                    $helperAudit['errors'][] = 'Helper image differs from the measured deployment.';
                }
            }
            if (($helpers['context']['bootId'] ?? null) !== ($declaration['deploymentOwner'] ?? null)
                || ($helpers['context']['namespace'] ?? null) !== ($declaration['workerNamespace'] ?? null)
                || (float) $helpers['from'] !== (float) $declaration['startedMonotonic'] || (float) $helpers['until'] !== (float) $completed) {
                $helperAudit['complete'] = false;
                $helperAudit['errors'][] = 'Helper identity or window differs from the run contract.';
            }
        }
        if (!$helperAudit['complete']) {
            $fail(($declaration['scenario'] === 'cold-startup' ? 'Startup' : 'Scenario') . ' helper measurement is incomplete or failed.');
        }
        foreach (['normalExit', 'pairRetired', 'dataVolumesRetained', 'fixturesRemoved'] as $field) {
            if (($evidence['cleanup'][$field] ?? null) !== true) {
                $fail('Shutdown, retirement or cleanup evidence is incomplete.');
            }
        }
        $status = $failures === [] ? 'passed' : 'failed';
        $workloadFailures = array_keys($failures);
        $insufficientMemory = null;
        if ($declaration['scenario'] === 'insufficient-memory') {
            $insufficientMemory = self::insufficientMemory($measured['deploymentOomKills'], $oomKilledContainers, $evidence['playback'] ?? []);
            $failures = [];
            if (($evidence['contractFingerprint'] ?? null) !== $contract->fingerprint() || $measured['samples'] === 0) {
                $failures['The insufficient-memory evidence does not match its declared, measured run.'] = true;
            }
            if (!$insufficientMemory['observed']) {
                $failures['The deployment completed without an OOM kill or a playback stall.'] = true;
            }
            $status = $failures === [] ? 'passed' : 'failed';
        }
        return ['version' => 1, 'status' => $status, 'scenario' => $declaration['scenario'], 'capability' => $declaration['capability'], 'size' => $declaration['size'],
            'players' => $declaration['players'], 'contractFingerprint' => $contract->fingerprint(),
            'failures' => array_keys($failures), 'workloadFailures' => $workloadFailures, 'insufficientMemory' => $insufficientMemory, 'configuredLimits' => $declaration['configuredLimits'], 'measured' => $measured, 'operatorMeasurements' => $operatorAudits, 'helperMeasurements' => $helperAudit,
            'memoryFitSupported' => $insufficientMemory === null && $failures === [] && $measured['swapGrowthBytes'] === 0 && $measured['cgroupSwapGrowthBytes'] === 0, 'scope' => 'single-run',
            'maxAttempts' => ['scan' => $evidence['queues']['scan']['maxAttempts'] ?? null, 'metadata' => $evidence['queues']['metadata']['maxAttempts'] ?? null],
            'retries' => ['scan' => $evidence['queues']['scan']['retried'] ?? null, 'metadata' => $evidence['queues']['metadata']['retried'] ?? null]];
    }

    /**
     * A run below the claimed size shows the failure the claim guards against: the kernel killed a
     * deployment process for memory, or a player stalled.
     *
     * @param list<mixed> $oomKilledContainers
     * @param mixed $playback
     * @return array{observed:bool,oomKills:int,oomKilledContainers:list<mixed>,stalledPlayers:list<string>}
     */
    public static function insufficientMemory(int $oomKills, array $oomKilledContainers, mixed $playback): array
    {
        $stalled = [];
        foreach (is_array($playback) ? $playback : [] as $record) {
            if (is_array($record) && ($record['stalled'] ?? null) === true && is_string($record['id'] ?? null)) {
                $stalled[] = $record['id'];
            }
        }
        return ['observed' => $oomKills > 0 || $oomKilledContainers !== [] || $stalled !== [], 'oomKills' => $oomKills,
            'oomKilledContainers' => $oomKilledContainers, 'stalledPlayers' => $stalled];
    }

    /** @param list<array<string,mixed>> $receipts
     * @param array<string,mixed> $context
     * @param array<string,int> $requiredActions
     * @return array<string,mixed>
     */
    public static function operatorAudit(array $receipts, array $context, array $requiredActions, float $from, float $until): array
    {
        $errors = [];
        $counts = [];
        $invocationPeaks = [];
        $nonces = [];
        $peak = $swapPeak = $oom = $failedExits = $included = $excluded = $pids = 0;
        // A not-ready readiness probe is an expected poll only when a later probe confirmed readiness.
        $notReadyProbes = [];
        $readyProbes = [];
        $pidsPeakAvailable = true;
        if (!is_finite($from) || !is_finite($until) || $from < 0 || $until < $from || count($receipts) > 4096
            || !is_string($context['namespace'] ?? null) || $context['namespace'] === ''
            || !is_string($context['bootId'] ?? null) || $context['bootId'] === '') {
            return ['complete' => false, 'errors' => ['Invalid operator measurement context or interval.']];
        }
        foreach ($receipts as $receipt) {
            $start = $receipt['startedMonotonic'] ?? null;
            $end = $receipt['finishedMonotonic'] ?? null;
            if (($receipt['status'] ?? null) === 'complete' && ($receipt['errors'] ?? null) === [] && self::number($start) && self::number($end) && $start >= 0 && $end >= $start && ($end < $from || $start > $until)) {
                ++$excluded;
                continue;
            }
            ++$included;
            $action = $receipt['action'] ?? null;
            $nonce = $receipt['nonce'] ?? null;
            if (($receipt['version'] ?? null) !== 1 || ($receipt['status'] ?? null) !== 'complete'
                || ($receipt['errors'] ?? null) !== [] || !is_string($action) || !is_string($nonce)
                || preg_match('/^[a-f0-9]{32}$/D', $nonce) !== 1 || isset($nonces[$nonce])
                || !self::number($start) || !self::number($end) || $start > $end) {
                $errors[] = 'Incomplete, repeated or unbound operator invocation.';
                $docker = is_array($receipt['docker'] ?? null) ? $receipt['docker'] : [];
                $oom += (int) (($docker['oomKilled'] ?? null) === true);
                $failedExits += (int) (is_int($docker['exitCode'] ?? null) && $docker['exitCode'] !== 0);
                $invocationPeaks[] = ['action' => $action, 'nonce' => $nonce, 'containerId' => $receipt['containerId'] ?? null,
                    'peakMemoryBytes' => $receipt['cgroup']['memoryPeakBytes'] ?? null,
                    'peakSwapBytes' => $receipt['cgroup']['swapPeakBytes'] ?? null, 'exitCode' => $docker['exitCode'] ?? null, 'oomKilled' => $docker['oomKilled'] ?? null];
                continue;
            }
            $nonces[$nonce] = true;
            $counts[$action] = ($counts[$action] ?? 0) + 1;
            $image = isset($context['actionImages']) ? ($context['actionImages'][$action] ?? null)
                : ($action === 'ipc-initialize' ? ($context['workerImage'] ?? null) : ($context['operatorImage'] ?? null));
            if (($receipt['namespace'] ?? null) !== $context['namespace'] || ($receipt['bootId'] ?? null) !== $context['bootId']
                || !is_string($image) || ($receipt['imageId'] ?? null) !== $image
                || !is_string($receipt['containerId'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $receipt['containerId']) !== 1) {
                $errors[] = 'Operator identity differs from the measured worker deployment.';
            }
            $group = $receipt['cgroup'] ?? [];
            $docker = $receipt['docker'] ?? [];
            $invocationPeaks[] = ['action' => $action, 'nonce' => $nonce, 'containerId' => $receipt['containerId'] ?? null,
                'peakMemoryBytes' => $group['memoryPeakBytes'] ?? null,
                'peakSwapBytes' => $group['swapPeakBytes'] ?? null, 'exitCode' => $docker['exitCode'] ?? null, 'oomKilled' => $docker['oomKilled'] ?? null];
            // Operator invocations run without a memory limit in their own cgroup namespace.
            if (($group['memoryMaxBytes'] ?? null) !== 'max' || ($group['swapMaxBytes'] ?? null) !== 'max'
                || ($docker['cgroupNamespace'] ?? null) !== 'private' || ($group['path'] ?? null) !== '/'
                || !is_int($group['memoryPeakBytes'] ?? null) || $group['memoryPeakBytes'] < 0
                || !is_int($group['swapPeakBytes'] ?? null) || $group['swapPeakBytes'] < 0 || !is_string($group['identity'] ?? null) || $group['identity'] === ''
                || !is_array($group['memoryEvents'] ?? null)
                || !is_int($group['memoryEvents']['oom'] ?? null) || !is_int($group['memoryEvents']['oom_kill'] ?? null)
                || $group['memoryEvents']['oom'] < 0 || $group['memoryEvents']['oom_kill'] < 0) {
                $errors[] = 'Operator unlimited cgroup, peak or cgroup event evidence is missing or inconsistent.';
            } else {
                if (is_int($group['pidsPeak'] ?? null)) {
                    $pids = max($pids, $group['pidsPeak']);
                } else {
                    $pidsPeakAvailable = false;
                    $pids = max($pids, is_int($group['pidsCurrent'] ?? null) ? $group['pidsCurrent'] : 0);
                }
                $peak = max($peak, $group['memoryPeakBytes']);
                $swapPeak = max($swapPeak, $group['swapPeakBytes']);
                $oom += $group['memoryEvents']['oom'] + $group['memoryEvents']['oom_kill'];
            }
            if (!is_int($docker['exitCode'] ?? null) || ($docker['running'] ?? null) !== false || !is_bool($docker['oomKilled'] ?? null)) {
                $errors[] = 'Operator final Docker state was not observed.';
            } else {
                if ($action === 'readiness' && $docker['exitCode'] === 1 && $docker['oomKilled'] === false) {
                    $notReadyProbes[] = $start;
                } else {
                    $failedExits += (int) ($docker['exitCode'] !== 0);
                }
                if ($action === 'readiness' && $docker['exitCode'] === 0) {
                    $readyProbes[] = $start;
                }
                $oom += (int) $docker['oomKilled'];
            }
        }
        $readinessPolls = 0;
        foreach ($notReadyProbes as $probe) {
            if (array_any($readyProbes, static fn(float|int $ready): bool => $ready > $probe)) {
                ++$readinessPolls;
            } else {
                ++$failedExits;
            }
        }
        foreach ($requiredActions as $action => $minimum) {
            if ($minimum < 0 || ($counts[$action] ?? 0) < $minimum) {
                $errors[] = 'Required operator action was not measured: ' . $action;
            }
        }
        if ($oom > 0) {
            $errors[] = 'Operator OOM activity was observed.';
        }
        if ($failedExits > 0) {
            $errors[] = 'Operator invocation exited unsuccessfully.';
        }
        if ($swapPeak > 0) {
            $errors[] = 'Operator used swap.';
        }
        return ['complete' => $errors === [], 'errors' => array_values(array_unique($errors)), 'invocations' => $included,
            'excludedOutsideInterval' => $excluded, 'actions' => $counts,
            'invocationPeaks' => $invocationPeaks, 'peakMemoryBytes' => $peak, 'peakSwapBytes' => $swapPeak, 'oomEvents' => $oom, 'failedExits' => $failedExits, 'readinessPolls' => $readinessPolls, 'maximumObservedPids' => $pids, 'pidsPeakAvailableForAll' => $pidsPeakAvailable,
            'peakScope' => 'Lifetime peak of each invocation intersecting this phase; reported separately, never added to sampled cgroup totals.'];
    }

    private static function number(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private static function counter(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }
}
