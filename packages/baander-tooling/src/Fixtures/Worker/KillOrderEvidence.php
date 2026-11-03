<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

/**
 * Checks that worker units run without container resource limits and that their processes
 * carry the out-of-memory kill order and encoder priority the application assigns.
 */
final class KillOrderEvidence
{
    /** Scores and niceness the application assigns; the tooling tests pin them to the application's own constants. */
    public const int CONTROL_UNIT = -500;
    public const int EXECUTION_UNIT = 300;
    public const int MEDIA_WORK = 600;
    public const int NATIVE_COMMAND_NICENESS = 10;
    public const int CPU_PRESSURE_PROCESSES = 4;

    public static function unitScore(string $unit): int
    {
        return match ($unit) {
            'control' => self::CONTROL_UNIT,
            'execution' => self::EXECUTION_UNIT,
            default => throw new \InvalidArgumentException('Unknown worker unit: ' . $unit),
        };
    }

    /**
     * Requires a `docker inspect` entry of one worker unit to carry no CPU, memory or PID limit,
     * no CPU set and the unit's out-of-memory score.
     *
     * @param array<array-key,mixed> $inspection
     * @return array{limits:string,oomScoreAdj:int}
     */
    public static function unlimitedUnit(array $inspection, string $unit): array
    {
        $config = $inspection['HostConfig'] ?? null;
        Reports::require(is_array($config), 'Missing ' . $unit . ' unit host configuration');
        $unlimited = array_all(['Memory', 'MemorySwap', 'NanoCpus'], static fn(string $field): bool => array_key_exists($field, $config) && $config[$field] === 0)
            && array_all(['CpuQuota', 'CpuShares'], static fn(string $field): bool => ($config[$field] ?? 0) === 0)
            && array_key_exists('PidsLimit', $config) && in_array($config['PidsLimit'], [null, 0], true)
            && ($config['CpusetCpus'] ?? null) === '';
        Reports::require($unlimited, 'Worker ' . $unit . ' unit carries a container resource limit or CPU set');
        $score = self::unitScore($unit);
        Reports::require(($config['OomScoreAdj'] ?? null) === $score, 'Worker ' . $unit . ' unit does not start at out-of-memory score ' . $score);
        return ['limits' => 'none', 'oomScoreAdj' => $score];
    }

    /**
     * @param array<array-key,mixed> $control
     * @param array<array-key,mixed> $execution
     * @return array{control:array{limits:string,oomScoreAdj:int},execution:array{limits:string,oomScoreAdj:int}}
     */
    public static function unlimitedPair(array $control, array $execution): array
    {
        return ['control' => self::unlimitedUnit($control, 'control'), 'execution' => self::unlimitedUnit($execution, 'execution')];
    }

    /**
     * Checks the kill-order records one unit's sampler took. The supervisor and native daemon run
     * at the unit's score and the default priority; native commands run at the media work score
     * and nice 10, above both. A PHP media child starts at the control unit's score and raises
     * its own to the media work score as it starts, so a child may show the inherited score only
     * before it first shows the raised one.
     *
     * @param list<array<string,mixed>> $samples
     * @return array{samples:int,roles:array<string,int>,scores:array<string,int>,niceness:array<string,int>}
     */
    public static function processes(array $samples, string $unit): array
    {
        Reports::require($samples !== [], 'Missing ' . $unit . ' kill-order samples');
        $base = self::unitScore($unit);
        $roles = $unit === 'control'
            ? ['supervisor' => 0, 'media-child' => 0]
            : ['supervisor' => 0, 'native' => 0, 'native-command' => 0];
        $raised = [];
        foreach ($samples as $sample) {
            $records = $sample['killOrder'] ?? null;
            Reports::require(is_array($records) && array_is_list($records), 'Missing ' . $unit . ' kill-order sample');
            $present = [];
            foreach ($records as $record) {
                Reports::require(
                    is_array($record) && is_string($record['role'] ?? null) && array_key_exists($record['role'], $roles)
                    && is_int($record['pid'] ?? null) && is_int($record['nice'] ?? null) && is_int($record['oomScoreAdj'] ?? null),
                    'Malformed ' . $unit . ' kill-order record',
                );
                $role = $record['role'];
                $present[$role] = true;
                ++$roles[$role];
                if ($role === 'media-child') {
                    $identity = $record['pid'] . ':' . ($record['startTicks'] ?? '');
                    $inherited = !isset($raised[$identity]) && $record['oomScoreAdj'] === $base;
                    Reports::require(
                        $record['nice'] === 0 && ($inherited || $record['oomScoreAdj'] === self::MEDIA_WORK),
                        'PHP media child runs at score ' . $record['oomScoreAdj'] . ' and nice ' . $record['nice'] . ' instead of ' . self::MEDIA_WORK . ' and 0',
                    );
                    if (!$inherited) {
                        $raised[$identity] = true;
                    }
                    continue;
                }
                [$score, $nice] = $role === 'native-command' ? [self::MEDIA_WORK, self::NATIVE_COMMAND_NICENESS] : [$base, 0];
                Reports::require(
                    $record['oomScoreAdj'] === $score && $record['nice'] === $nice,
                    ucfirst($unit) . ' ' . $role . ' runs at score ' . $record['oomScoreAdj'] . ' and nice ' . $record['nice']
                    . ' instead of ' . $score . ' and ' . $nice,
                );
            }
            Reports::require(
                isset($present['supervisor']) && ($unit === 'control' || isset($present['native'])),
                ucfirst($unit) . ' kill-order sample lacks its resident roles',
            );
        }
        Reports::require(
            $unit === 'control' ? $raised !== [] : $roles['native-command'] > 0,
            'No ' . $unit . ' media work was observed at the media work score',
        );
        return [
            'samples' => count($samples),
            'roles' => $roles,
            'scores' => $unit === 'control' ? ['supervisor' => $base, 'media-child' => self::MEDIA_WORK]
                : ['supervisor' => $base, 'native' => $base, 'native-command' => self::MEDIA_WORK],
            'niceness' => $unit === 'control' ? ['supervisor' => 0, 'media-child' => 0]
                : ['supervisor' => 0, 'native' => 0, 'native-command' => self::NATIVE_COMMAND_NICENESS],
        ];
    }

    /**
     * Whether sampled arguments belong to a running native command rather than to a launcher step
     * (setsid, nice or choom) that has not yet replaced itself with the command.
     *
     * @param list<string> $arguments
     */
    public static function nativeCommand(array $arguments): bool
    {
        $program = basename($arguments[0] ?? '');
        return in_array($program, ['ffmpeg', 'ffprobe'], true)
            || (str_starts_with($program, 'php') && basename($arguments[1] ?? '') === 'ffmpeg');
    }

    /**
     * Requires the CPU pressure processes that stand in for encodes to run as encodes do.
     *
     * @param list<array<string,mixed>> $pressure
     * @return array{processes:int,nice:int,oomScoreAdj:int}
     */
    public static function pressureProcesses(array $pressure): array
    {
        $burners = array_values(array_filter($pressure, static fn(array $row): bool => array_key_exists('burner', $row)));
        Reports::require(count($burners) === self::CPU_PRESSURE_PROCESSES, 'CPU pressure processes did not all report their priority');
        Reports::require(
            array_all($burners, static fn(array $row): bool => ($row['nice'] ?? null) === self::NATIVE_COMMAND_NICENESS
                && ($row['oomScoreAdj'] ?? null) === self::MEDIA_WORK),
            'CPU pressure processes did not run at the encoder priority',
        );
        return ['processes' => count($burners), 'nice' => self::NATIVE_COMMAND_NICENESS, 'oomScoreAdj' => self::MEDIA_WORK];
    }
}
