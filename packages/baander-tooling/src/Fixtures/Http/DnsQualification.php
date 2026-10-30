<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Http;

use Baander\Tooling\Fixtures\Runtime;
use RuntimeException;

final class DnsQualification
{
    public static function main(string $root): int
    {
        $runtime = new Runtime($root, budget: 'http');
        try {
            $image = $runtime->image('application');
            $layout = $runtime->layout();
            return ContainerQualification::run(
                $runtime,
                'baander-dns',
                [
                    '--privileged',
                    '--network', 'none',
                    '--entrypoint', 'sleep',
                    '-e', 'XDEBUG_MODE=off',
                    $image->id,
                    'infinity',
                ],
                static function (Runtime $drill, string $container, string $artifacts) use ($root, $layout): void {
                    $directory = $layout->root;
                    if (($drill->environment['BAANDER_TEST_CHECKOUT_IN_IMAGE'] ?? '') !== '1') {
                        $directory = '/tmp/baander-dns-runtime';
                        $archive = $artifacts . '/checkout.tar';
                        $drill->run([
                            'tar',
                            '-cf', $archive,
                            '-C', $root,
                            'vendor',
                            'scripts/vendor',
                            'scripts/composer.json',
                            'scripts/composer.lock',
                            'scripts/bootstrap.php',
                            'src',
                            'tests',
                            'bin',
                            'composer.json',
                            'composer.lock',
                            'packages',
                        ]);
                        $drill->docker()->run(['cp', $archive, $container . ':/tmp/baander-dns-checkout.tar']);
                        $drill->docker()->run([
                            'exec',
                            $container,
                            'sh',
                            '-ec',
                            'mkdir -p ' . $directory . '; tar -xf /tmp/baander-dns-checkout.tar -C ' . $directory . '; rm /tmp/baander-dns-checkout.tar',
                        ]);
                        unlink($archive);
                    }
                    $drill->docker()->run([
                        'exec',
                        '--user', '0',
                        $container,
                        'sh',
                        '-ec',
                        'printf "nameserver 127.0.0.1\noptions attempts:1 timeout:1\n" > /etc/resolv.conf',
                    ]);
                    $drill->docker()->run([
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
                        if ($drill->docker()->run(['exec', $container, 'test', '-f', '/tmp/baander-dns-ready'], check: false)->exitCode === 0) {
                            $ready = true;
                            break;
                        }
                        usleep(50_000);
                    }
                    if (!$ready) {
                        throw new RuntimeException('DNS fixture did not become ready');
                    }
                    $result = $drill->docker()->run(
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
            );
        } finally {
            $runtime->close();
        }
    }
}
