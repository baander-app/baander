<?php

declare(strict_types=1);

/**
 * Reads, inside one disposable worker unit, the out-of-memory score and niceness of the
 * processes the kill order ranks: the supervisor (PID 1), the native daemon, PHP media
 * children and the native commands the daemon launched. Launcher steps (setsid, nice, choom)
 * are skipped: each replaces itself in place and the command carries its priority once it runs.
 */
final class KillOrderSample
{
    /**
     * Records carry the program name rather than the argument vector: samplers take one every
     * fifty milliseconds, and FFmpeg argument vectors would make the evidence files too large.
     *
     * @return list<array{pid:int,startTicks:string,role:string,nice:int,oomScoreAdj:int,program:string}>
     */
    public static function processes(string $proc = '/proc'): array
    {
        $records = [];
        foreach (glob($proc . '/[0-9]*', GLOB_ONLYDIR) ?: [] as $path) {
            $raw = @file_get_contents($path . '/cmdline');
            if (!is_string($raw) || $raw === '') {
                continue; // Kernel threads and exiting processes carry no arguments.
            }
            $arguments = explode("\0", rtrim($raw, "\0"));
            $pid = (int) basename($path);
            $role = self::role($pid, $arguments);
            $stat = @file_get_contents($path . '/stat');
            $score = @file_get_contents($path . '/oom_score_adj');
            if ($role === null || !is_string($stat) || !is_string($score) || ($end = strrpos($stat, ') ')) === false) {
                continue;
            }
            $fields = explode(' ', substr($stat, $end + 2));
            $records[] = [
                'pid' => $pid,
                'startTicks' => $fields[19] ?? '',
                'role' => $role,
                'nice' => (int) ($fields[16] ?? 0),
                'oomScoreAdj' => (int) trim($score),
                'program' => basename($arguments[0] ?? ''),
            ];
        }
        return $records;
    }

    /** @param list<string> $arguments */
    public static function role(int $pid, array $arguments): ?string
    {
        $program = basename($arguments[0] ?? '');
        $runs = static fn(string $script): bool => array_any($arguments, static fn(string $argument): bool => str_ends_with($argument, $script));
        return match (true) {
            $pid === 1 && in_array('app:worker', $arguments, true) => 'supervisor',
            in_array($program, ['ffmpeg', 'ffprobe'], true),
            str_starts_with($program, 'php') && basename($arguments[1] ?? '') === 'ffmpeg' => 'native-command',
            $runs('/bin/worker-native.php') => 'native',
            $runs('/bin/worker-media-job.php') => 'media-child',
            default => null,
        };
    }
}
