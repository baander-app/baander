<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

/**
 * Judges a load proof receipt. A receipt passes only when every configured video and audio job
 * was admitted and completed with all slots busy at once; every video admission of the run was
 * decided by an active governor that had learned, without a veto or downgrade; nothing was
 * contained, OOM-killed or failed uncertainly in native execution; PostgreSQL's backends stayed
 * within the pools and the direct reserve while the application pool was saturated; every lease
 * helper answered within its deadline; the daemon answered every new peer in time; the kill
 * order held; and each stream's real-time margin and encode path were recorded. Real time
 * itself is recorded, not judged.
 */
final class MediaLoadVerifier
{
    /**
     * @param array<string,mixed> $receipt
     * @return list<string>
     */
    public static function failures(array $receipt): array
    {
        $failures = [];
        $check = static function (bool $condition, string $failure) use (&$failures): void {
            if (!$condition) {
                $failures[] = $failure;
            }
        };
        $video = (int) $receipt['profile']['videoEncoderSlots'];
        $audio = (int) $receipt['profile']['audioEncoderSlots'];
        $streams = $receipt['video']['streams'];

        $check(count($streams) === $video, 'The run admitted ' . count($streams) . ' video jobs instead of ' . $video);
        $check(array_all($streams, static fn(array $stream): bool => $stream['completedAt'] !== null), 'A video job did not run to completion');
        $check(
            $receipt['audio']['firstWave']['count'] === $audio && $receipt['audio']['firstWave']['ok'] === $audio,
            'The first audio wave completed ' . $receipt['audio']['firstWave']['ok'] . ' of ' . $audio . ' renditions',
        );
        $check($receipt['audio']['failed'] === 0, $receipt['audio']['failed'] . ' audio renditions failed');
        $check($receipt['occupancy']['everySlotSamples'] > 0, 'No sample saw every video and audio slot busy at once');

        $governor = $receipt['governor'];
        $before = $governor['beforeRun'];
        $check(
            is_array($before) && $before['state'] === 'active' && $before['samples'] >= $governor['learningSamples'],
            'The governor had not learned before the run: ' . json_encode($before),
        );
        $check(count($governor['runDecisions']) === $video, 'The run recorded ' . count($governor['runDecisions']) . ' admission decisions instead of ' . $video);
        foreach ($governor['runDecisions'] as $decision) {
            $check(($decision['governorState'] ?? null) === 'active', 'Video job ' . $decision['jobId'] . ' was admitted while the governor was ' . ($decision['governorState'] ?? 'unrecorded'));
            $check(
                ($decision['allowedTier'] ?? null) === ($decision['requestedTier'] ?? null),
                'Video job ' . $decision['jobId'] . ' was downgraded from ' . ($decision['requestedTier'] ?? '?') . ' to ' . ($decision['allowedTier'] ?? '?'),
            );
        }
        $check($governor['vetoes'] === [], count($governor['vetoes']) . ' admissions were vetoed');

        $check($receipt['failures']['containment'] === [], 'The media role was contained: ' . json_encode($receipt['failures']['containment']));
        $check($receipt['failures']['nativeFailures'] === [], count($receipt['failures']['nativeFailures']) . ' native execution failures were logged');
        $check(
            array_all($receipt['containment']['unitsAfterRun'], static fn(array $unit): bool => $unit['running'] && $unit['restarts'] === 0),
            'A worker unit stopped or restarted during the run',
        );
        $check($receipt['containment']['drainingHeartbeats'] === 0, 'A worker unit drained during the run');
        $check(array_all($receipt['containment']['leaseEpochs'], static fn(array $epochs): bool => count($epochs) === 1), 'A worker unit changed lease epoch');
        $check($receipt['oom']['dockerEvents'] === 0, $receipt['oom']['dockerEvents'] . ' Docker OOM events');
        $check(array_all($receipt['oom']['unitOomKills'], static fn(?int $kills): bool => $kills === 0), 'A worker unit recorded an OOM kill');
        $check($receipt['oom']['oomKilledContainers'] === [], 'OOM-killed containers: ' . implode(', ', $receipt['oom']['oomKilledContainers']));

        $launches = $receipt['nativeLaunches'];
        $check($launches['overlappingLaunches'] === 0 && $launches['duplicateGenerations'] === 0, 'Native launches overlapped or repeated a generation');
        $check(array_all($launches['runJobLaunches'], static fn(int $count): bool => $count === 1), 'A run video job was launched other than exactly once');
        $native = $receipt['native'];
        $check($native['errors'] === [], count($native['errors']) . ' native daemon probes failed');
        $check(
            ($native['connectToAnswer']['maxSeconds'] ?? INF) < $native['deadlineSeconds'],
            'The native daemon took ' . ($native['connectToAnswer']['maxSeconds'] ?? 'unmeasured') . ' seconds to answer a new peer',
        );

        $database = $receipt['database'];
        $check($database['samples'] > 0 && $database['runSamples'] > 0 && $database['errors'] === 0, 'PostgreSQL backends were not sampled throughout the run');
        foreach (['application', 'control', 'total'] as $kind) {
            $check(
                $database['peak'][$kind] <= $database['limits'][$kind],
                'PostgreSQL ' . $kind . ' backends peaked at ' . $database['peak'][$kind] . ' above ' . $database['limits'][$kind],
            );
        }
        $check(
            $database['pools']['application']['saturatedRunSamples'] * 2 >= $database['runSamples'],
            'Web load saturated the application pool in only ' . $database['pools']['application']['saturatedRunSamples'] . ' of ' . $database['runSamples'] . ' run samples',
        );

        foreach (['control', 'execution'] as $unit) {
            $leases = $receipt['leases'][$unit];
            $check($leases['runHelpers'] > 0, 'No ' . $unit . ' lease helper ran during the run');
            $check($leases['overDeadline'] === [], count($leases['overDeadline']) . ' ' . $unit . ' lease helpers exceeded the ' . $receipt['leases']['deadlineSeconds'] . '-second deadline');
        }

        foreach (['control', 'execution'] as $unit) {
            $check(($receipt['killOrder'][$unit]['samples'] ?? 0) > 0, 'The ' . $unit . ' kill order was not sampled');
        }
        foreach ($streams as $stream) {
            $check($stream['steadySpeed'] !== null && $stream['realTimeMargin'] !== null, 'Video job ' . $stream['job'] . ' has no recorded real-time margin');
            $check(
                $stream['path'] === $receipt['video']['expectedPath'],
                'Video job ' . $stream['job'] . ' encoded on the ' . ($stream['path'] ?? 'unrecorded') . ' path in the ' . $receipt['video']['expectedPath'] . ' pass',
            );
        }
        return $failures;
    }

    /** @param list<string> $arguments receipt paths */
    public static function main(array $arguments): int
    {
        if ($arguments === []) {
            throw new \InvalidArgumentException('Expected one or more load proof receipts', 2);
        }
        $status = 0;
        foreach ($arguments as $path) {
            $failures = self::failures(Reports::json($path));
            echo ($failures === [] ? 'PASS ' : 'FAIL ') . $path, "\n";
            foreach ($failures as $failure) {
                echo '  - ', $failure, "\n";
            }
            $status = $failures === [] ? $status : 1;
        }
        return $status;
    }
}
