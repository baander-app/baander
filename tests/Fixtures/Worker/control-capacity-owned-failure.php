<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$mode = $argv[1] ?? '';
$boot = (string) getenv('BAANDER_WORKER_BOOT_ID');
$peer = json_decode((string) file_get_contents('/run/baander-worker/' . $boot . '/control.json'), true, flags: JSON_THROW_ON_ERROR);
$evidence = ['phase' => $mode, 'identity' => ['namespace' => $peer['namespace'], 'bootId' => $boot, 'epoch' => $peer['epoch']]];
$client = new App\Shared\Infrastructure\Worker\NativeExecutionClient('/run/baander-worker/' . $boot . '/native.sock', $peer['namespace'], $boot, $peer['epoch'], 'capacity-owned-' . $mode, 1);
$deadline = hrtime(true) + 25_000_000_000;
$attempts = 0;
while (hrtime(true) < $deadline) {
    ++$attempts;
    try {
        $command = ['ffprobe', '-v', 'error', '-show_format', '/media/source/track.flac'];
        $handle = $client->submit($command, 20);
        do {
            $result = $client->inspect($handle);
            if (($result['state'] ?? '') !== 'running') {
                echo json_encode($evidence + ['ownedTerminal' => $result, 'attempts' => $attempts], JSON_THROW_ON_ERROR) . "\n";
                flush();
                $client->release($handle);
                if (($result['exitCode'] ?? 0) !== 0) { exit(0); }
                break;
            }
            usleep(20000);
        } while (hrtime(true) < $deadline);
    } catch (App\Shared\Infrastructure\Worker\NativeExecutionException $error) {
        echo json_encode($evidence + ['nativeFailure' => $error->getMessage(), 'attempts' => $attempts], JSON_THROW_ON_ERROR) . "\n";
        flush();
        exit(0);
    }
    usleep(20000);
}
throw new RuntimeException('No owned native failure observed within deadline.');
