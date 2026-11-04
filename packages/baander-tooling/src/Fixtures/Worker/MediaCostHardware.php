<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Resources\DisposableContainer;

/**
 * The media-cost drill's hardware mode: runs the application's 1080p hardware encode command
 * in a disposable worker-image container that receives the device and its group the way the
 * execution unit does, alone and at each requested concurrency, and publishes the receipt.
 */
final class MediaCostHardware
{
    private const string DEFAULT_STEPS = '1,2,4,8,12,16,24';

    public static function main(string $root): int
    {
        $environment = getenv();
        $image = $environment['BAANDER_TEST_IMAGE'] ?? '';
        $device = $environment['BAANDER_TEST_MEDIA_COST_DEVICE'] ?? '/dev/dri/renderD128';
        $encoders = $environment['BAANDER_TEST_MEDIA_COST_ENCODERS'] ?? 'h264_vaapi,hevc_vaapi';
        $steps = $environment['BAANDER_TEST_MEDIA_COST_STEPS'] ?? self::DEFAULT_STEPS;
        $stepSeconds = $environment['BAANDER_TEST_MEDIA_COST_STEP_SECONDS'] ?? '150';
        if ($image === '' || preg_match('~\A/dev/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\z~D', $device) !== 1
            || preg_match('/\A[a-z0-9_]+(?:,[a-z0-9_]+)*\z/D', $encoders) !== 1
            || preg_match('/\A[1-9][0-9]{0,2}(?:,[1-9][0-9]{0,2})*\z/D', $steps) !== 1
            || preg_match('/\A[1-9][0-9]{0,3}\z/D', $stepSeconds) !== 1) {
            throw new \InvalidArgumentException('Hardware cost mode requires BAANDER_TEST_IMAGE, a /dev device path, encoder and concurrency lists and whole step seconds.');
        }
        clearstatcache(true, $device);
        $status = @stat($device);
        if ($status === false || @filetype($device) !== 'char') {
            throw new \InvalidArgumentException('Hardware cost mode requires a character device: ' . $device);
        }
        $runs = count(explode(',', $encoders)) * count(explode(',', $steps));
        $budget = $runs * ((int) $stepSeconds + 15) + 300;
        $runtime = new Runtime($root, seconds: $budget + 120);
        $artifacts = Runtime::artifacts($root, 'media-cost-hardware');
        try {
            $docker = $runtime->docker();
            DisposableContainer::run($docker, $artifacts . '/hardware-resources.json', [
                '--network', 'none', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--cgroupns', 'private',
                '--device', $device . ':' . $device . ':rwm', '--group-add', (string) $status['gid'],
                '--tmpfs', '/work:rw,nosuid,nodev,size=6g,mode=1777',
                '--entrypoint', 'php', $image, 'tests/Fixtures/Worker/media-cost-hardware.php', $device, $stepSeconds, $encoders, $steps, '/work',
            ], $runtime->environment, 60, timeout: $budget, logPath: $artifacts . '/hardware-samples.jsonl', stderrLogPath: $artifacts . '/hardware-drill.log');
            $receipt = MediaCost::hardwareReceipt($artifacts . '/hardware-samples.jsonl');
            Reports::publish($artifacts . '/media-cost-hardware.json', $receipt);
            echo json_encode(['artifacts' => $artifacts, 'largestRealTimeConcurrency' => $receipt['largestRealTimeConcurrency']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
            return 0;
        } finally {
            $runtime->close();
        }
    }
}
