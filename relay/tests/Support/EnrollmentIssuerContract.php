<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Baander\RegistryTests;

final class EnrollmentIssuerContract
{
    public static function run(string $python, string $issuer): int
    {
        $checks = 0;
        $check = static function (bool $condition, string $message) use (&$checks): void {
            Runtime::requireThat($condition, $message);
            ++$checks;
        };
        $registration = ['publicId' => 'security_server', 'apiKey' => str_repeat('a', 64)];
        $check(
            Enrollment::issueToken(str_repeat('k', 32), $registration, 61000)
                === 'v1.61000.a5028ecb4a811b8abe92975c10ffe3ba1c9912d7c779c99e2f3c556ea3595977',
            'Independent HMAC oracle failed its known vector.',
        );
        $root = Runtime::temporaryDirectory('registry-enrollment-contract-');
        try {
            $key = $root . '/key';
            file_put_contents($key, str_repeat('k', 32));
            $command = [
                $python, $issuer,
                '--key-file', $key,
                '--public-id', $registration['publicId'],
                '--credential-digest', hash('sha256', $registration['apiKey']),
            ];
            foreach ([1, 60, 240, 300] as $ttl) {
                $before = (int) floor(microtime(true) * 1000);
                $result = Runtime::run([...$command, '--ttl-seconds', (string) $ttl]);
                $after = (int) floor(microtime(true) * 1000);
                $check($result['code'] === 0 && $result['stderr'] === '', 'Enrollment CLI failed valid TTL.');
                $expiry = self::expiry($result['stdout']);
                $check($expiry >= $before + $ttl * 1000 && $expiry <= $after + $ttl * 1000, 'Enrollment expiry outside requested TTL.');
                $check($result['stdout'] === Enrollment::issueToken(str_repeat('k', 32), $registration, $expiry) . "\n", 'Enrollment CLI signature mismatch.');
            }
            $before = (int) floor(microtime(true) * 1000);
            $result = Runtime::run($command);
            $after = (int) floor(microtime(true) * 1000);
            $expiry = self::expiry($result['stdout']);
            $check($result['code'] === 0 && $expiry >= $before + 240000 && $expiry <= $after + 240000, 'Default enrollment TTL changed.');
            $check($result['stdout'] === Enrollment::issueToken(str_repeat('k', 32), $registration, $expiry) . "\n", 'Default enrollment signature mismatch.');
            foreach (['0', '301', '-1', '1.5'] as $ttl) {
                $result = Runtime::run([...$command, '--ttl-seconds', $ttl]);
                $check($result['code'] !== 0 && $result['stdout'] === '', 'Invalid TTL issued a capability.');
            }
            foreach ([31, 4097, 6000] as $length) {
                file_put_contents($key, str_repeat('secret', (int) ceil($length / 6)));
                file_put_contents($key, substr(file_get_contents($key), 0, $length));
                $result = Runtime::run($command);
                $check(
                    $result['code'] !== 0 && $result['stdout'] === '' && !str_contains($result['stderr'], 'secret'),
                    'Invalid key issued a capability or disclosed key bytes.',
                );
            }
            $maximumKey = str_repeat('k', 4096);
            file_put_contents($key, $maximumKey);
            $result = Runtime::run($command);
            $expiry = self::expiry($result['stdout']);
            $check(
                $result['code'] === 0 && $result['stdout'] === Enrollment::issueToken($maximumKey, $registration, $expiry) . "\n",
                'Maximum-size valid key was rejected.',
            );
            file_put_contents($key, str_repeat('k', 32));
            foreach (['', "server\nother", str_repeat('s', 129)] as $identity) {
                $invalid = $command;
                $invalid[5] = $identity;
                $result = Runtime::run($invalid);
                $check($result['code'] !== 0 && $result['stdout'] === '', 'Invalid identity issued a capability.');
            }
            foreach ([str_repeat('A', 64), str_repeat('a', 63), ''] as $digest) {
                $invalid = $command;
                $invalid[7] = $digest;
                $result = Runtime::run($invalid);
                $check($result['code'] !== 0 && $result['stdout'] === '', 'Invalid digest issued a capability.');
            }
            unlink($key);
            $result = Runtime::run($command);
            $check($result['code'] !== 0 && $result['stdout'] === '', 'Missing key file issued a capability.');
            return $checks;
        } finally {
            Runtime::removeTree($root);
        }
    }

    private static function expiry(string $output): int
    {
        $parts = $output
            |> trim(...)
            |> (static fn(string $token): array => explode('.', $token));
        Runtime::requireThat(count($parts) === 3 && preg_match('/^[0-9]+$/D', $parts[1]) === 1, 'Issuer returned a malformed capability.');
        return (int) $parts[1];
    }
}
