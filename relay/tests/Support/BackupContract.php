<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Baander\RegistryTests;

final class BackupContract
{
    public static function run(string $python, string $coordinator): int
    {
        $fixture = new BackupFixture($python, $coordinator);
        $checks = 0;
        try {
            $check = static function (bool $condition, string $message) use (&$checks): void {
                Runtime::requireThat($condition, $message);
                ++$checks;
            };
            $check($fixture->invoke(command: 'health')['code'] === 1, 'Empty status was healthy.');
            $check($fixture->invoke()['code'] === 0, 'Initial backup failed.');
            $first = $fixture->status()['last_success'];
            $check($fixture->invoke()['code'] === 0, 'Historical backup failed.');
            $status = $fixture->status();
            $second = $status['last_success'];
            $objects = glob($fixture->root . '/objects/*');
            $check(count($objects) === 2 && $first['object_key'] !== $second['object_key'], 'Backup overwrote history.');
            foreach ([$first, $second] as $success) {
                $check($success['capture_started_at'] <= $success['upload_completed_at'], 'Capture timestamp follows completion.');
                $check(hash_file('sha256', $fixture->root . '/objects/' . basename($success['object_key'])) === $success['sha256'], 'Published ciphertext hash is wrong.');
            }
            $calls = $fixture->calls();
            $check(in_array('--immutable', $calls[array_key_last($calls)][2], true), 'Upload was not immutable.');
            $check(!str_contains(json_encode([$calls, $status], JSON_THROW_ON_ERROR), 'private-password'), 'Backup disclosed credentials.');
            $fixture->assertClean();

            foreach (['auth', 'redirect', 'content', 'stream', 'tls', 'incomplete', 'encrypt', 'prefix', 'headers', 'upload'] as $mode) {
                $fixture->clearCalls();
                $result = $fixture->invoke($mode);
                $check($result['code'] === 1 && $result['stdout'] === '', 'Failed backup CLI accepted ' . $mode);
                $status = $fixture->status();
                $check($status['latest_attempt']['outcome'] === 'failed' && $status['last_success'] === $second, 'Failure refreshed success: ' . $mode);
                if ($mode !== 'upload') {
                    $check(!in_array('rclone', array_column($fixture->calls(), 0), true), 'Failed capture uploaded: ' . $mode);
                }
                $check($fixture->invoke(command: 'health')['code'] === 1, 'Latest failure was healthy: ' . $mode);
                $fixture->assertClean();
            }

            $fixture->config['max_ciphertext_bytes'] = 20;
            $fixture->clearCalls();
            $check($fixture->invoke()['code'] === 1, 'Oversized ciphertext accepted.');
            $check(!in_array('rclone', array_column($fixture->calls(), 0), true), 'Oversized ciphertext uploaded.');
            $fixture->assertClean();
            $fixture->config['max_ciphertext_bytes'] = 4096;
            $fixture->config['attempt_timeout_seconds'] = 0.2;
            foreach (['hang', 'upload-hang'] as $mode) {
                $fixture->clearCalls();
                $start = hrtime(true);
                $check($fixture->invoke($mode)['code'] === 1, 'Deadline accepted ' . $mode);
                $check((hrtime(true) - $start) / 1e9 < 2, 'Deadline did not bound child lifetime: ' . $mode);
                $fixture->assertClean();
            }

            $fixture->config['attempt_timeout_seconds'] = 2;
            $fixture->clearCalls();
            $worker = new Process($fixture->arguments(), $fixture->root . '/shutdown.log', $fixture->environment('hang'));
            try {
                $deadline = microtime(true) + 3;
                while (count($fixture->calls()) < 2 && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $check(count($fixture->calls()) >= 2, 'Capture children did not start.');
                $worker->signal(15);
                $exit = $worker->wait(3);
                $check($exit === 130, 'Shutdown did not report cancellation (' . $exit . '): ' . file_get_contents($fixture->root . '/shutdown.log'));
                $check($fixture->status()['latest_attempt']['outcome'] === 'failed', 'Shutdown did not publish failure.');
                $fixture->assertClean();
            } finally {
                $worker->close(false);
            }

            $fixture->config['attempt_timeout_seconds'] = 2;
            foreach ([[-301, 0], [-303, 1], [10, 1]] as [$offset, $expected]) {
                $captured = microtime(true) + $offset;
                $health = [
                    'last_success' => [
                        'capture_started_at' => self::timestamp($captured),
                        'upload_completed_at' => self::timestamp(microtime(true)),
                    ],
                ];
                file_put_contents($fixture->root . '/status.json', json_encode($health, JSON_THROW_ON_ERROR));
                $check($fixture->invoke(command: 'health')['code'] === $expected, 'Capture-age health bound was wrong for offset ' . $offset);
            }

            $valid = $fixture->config;
            $invalid = [
                ['rqlite_url', 'http://registry.baander.app'],
                ['rqlite_url', 'https://user:secret@registry.baander.app'],
                ['rqlite_url', 'https://registry.baander.app?secret'],
                ['rqlite_url', 'https://registry.baander.app#fragment'],
                ['attempt_timeout_seconds', 0],
                ['attempt_timeout_seconds', 300],
                ['attempt_timeout_seconds', true],
                ['max_ciphertext_bytes', 0],
                ['max_ciphertext_bytes', 1.5],
                ['max_ciphertext_bytes', true],
                ['destination', ':unnamed'],
                ['destination', 'missing-remote'],
                ['curl_config', ''],
            ];
            foreach ($invalid as [$key, $value]) {
                $fixture->config = [...$valid, $key => $value];
                $fixture->clearCalls();
                $result = $fixture->invoke();
                $check($result['code'] === 1 && $result['stdout'] === '' && $fixture->calls() === [], 'Invalid configuration reached tools: ' . $key);
                $check(!str_contains($result['stderr'], 'secret'), 'Invalid configuration exposed secrets.');
            }
            $fixture->config = $valid;
            foreach (["[backup]\ntype=s3\nendpoint=http://storage.baander.app\n", "[backup]\ntype=local\n"] as $remote) {
                file_put_contents($fixture->root . '/rclone.conf', $remote);
                $check($fixture->invoke()['code'] === 1, 'Insecure storage remote accepted.');
            }
            return $checks;
        } finally {
            $fixture->close();
        }
    }

    private static function timestamp(float $time): string
    {
        return new \DateTimeImmutable('@' . sprintf('%.6F', $time))->format('Y-m-d\TH:i:s.uP');
    }
}
