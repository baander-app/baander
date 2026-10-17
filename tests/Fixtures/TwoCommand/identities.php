<?php

declare(strict_types=1);

function startTicks(int $pid): ?int
{
    $stat = @file_get_contents('/proc/' . $pid . '/stat');
    if ($stat === false || preg_match('/^\d+ \(.*\) (.*)$/s', $stat, $match) !== 1) {
        return null;
    }
    return (int) explode(' ', $match[1])[19];
}
$input = stream_get_contents(STDIN);
if (($argv[1] ?? '') === 'capture') {
    $identities = [];
    foreach (explode("\n", $input) as $line) {
        if (preg_match('/^\s*(\d+)\s+/', $line, $match) === 1) {
            $pid = (int) $match[1];
            $ticks = startTicks($pid);
            if ($ticks !== null) {
                $identities[] = ['pid' => $pid, 'startTicks' => $ticks];
            }
        }
    }
    if ($identities === []) {
        throw new RuntimeException('Host process snapshot was empty.');
    }
    echo json_encode($identities, JSON_THROW_ON_ERROR) . "\n";
} elseif (($argv[1] ?? '') === 'verify') {
    $identities = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
    foreach ($identities as $identity) {
        if (startTicks($identity['pid']) === $identity['startTicks']) {
            throw new RuntimeException('Retired deployment process survived: ' . $identity['pid']);
        }
    }
    echo json_encode(['checkedIdentities' => count($identities), 'survivors' => 0], JSON_THROW_ON_ERROR) . "\n";
} else {
    throw new RuntimeException('Expected capture or verify.');
}
