<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Http;

use RuntimeException;

final class DnsQualification
{
    public static function main(string $root): int
    {
        return ContainerQualification::run(
            $root,
            'baander-dns',
            [
                '--privileged',
                '--network', 'none',
                '--entrypoint', 'sleep',
                '-e', 'XDEBUG_MODE=off',
                getenv('BAANDER_TEST_IMAGE') ?: 'martinjuul/baander-app:latest',
                'infinity',
            ],
            static function (Drill $drill, string $container, string $artifacts) use ($root): void {
                $directory = '/var/www/html';
                if (getenv('BAANDER_TEST_CHECKOUT_IN_IMAGE') !== '1') {
                    $directory = '/tmp/baander-dns-runtime';
                    $archive = $artifacts . '/checkout.tar';
                    $drill->run([
                        'tar',
                        '-cf', $archive,
                        '-C', $root,
                        'vendor',
                        'src',
                        'tests',
                        'bin',
                        'composer.json',
                        'composer.lock',
                        'packages',
                    ]);
                    $drill->docker(['cp', $archive, $container . ':/tmp/baander-dns-checkout.tar']);
                    $drill->docker([
                        'exec',
                        $container,
                        'sh',
                        '-ec',
                        'mkdir -p ' . $directory . '; tar -xf /tmp/baander-dns-checkout.tar -C ' . $directory . '; rm /tmp/baander-dns-checkout.tar',
                    ]);
                    unlink($archive);
                }
                $drill->docker([
                    'exec',
                    '--user', '0',
                    $container,
                    'sh',
                    '-ec',
                    'printf "nameserver 127.0.0.1\noptions attempts:1 timeout:1\n" > /etc/resolv.conf',
                ]);
                $drill->docker([
                    'exec',
                    '--user', '0',
                    '-d',
                    '-w', $directory,
                    $container,
                    'sh',
                    '-ec',
                    'exec php tests/Fixtures/Http/dns-server.php --ready-file /tmp/baander-dns-ready > /tmp/baander-dns-server.log 2>&1',
                ]);
                $ready = false;
                for ($attempt = 0; $attempt < 100; ++$attempt) {
                    if ($drill->docker(['exec', $container, 'test', '-f', '/tmp/baander-dns-ready'], check: false)->exitCode === 0) {
                        $ready = true;
                        break;
                    }
                    usleep(50_000);
                }
                if (!$ready) {
                    throw new RuntimeException('DNS fixture did not become ready');
                }
                $result = $drill->docker(
                    [
                        'exec',
                        '--user', 'www-data',
                        '-w', $directory,
                        $container,
                        'php',
                        'tests/Fixtures/Http/dns-runtime.php',
                    ],
                    timeout: null,
                );
                file_put_contents($artifacts . '/runtime.json', $result->stdout);
                fwrite(STDOUT, $result->stdout);
                if ($result->stderr !== '') {
                    fwrite(STDERR, $result->stderr);
                }
            },
            ['/tmp/baander-dns-server.log' => 'dns-server.log'],
            createTimeout: null,
        );
    }
}
