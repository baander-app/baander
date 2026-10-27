<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\Runtime;

final class Production
{
    /** @param array<string,mixed> $config */
    public static function validateConfiguration(array $config): void
    {
        Runtime::require(($config['User'] ?? null) === 'www-data', 'Worker must run as application user');
        Runtime::require(
            ($config['Entrypoint'] ?? null) === [
                '/usr/local/bin/php',
                '/var/www/html/bin/console',
                'app:worker',
                '--no-interaction',
            ],
            'Worker target entrypoint differs',
        );
        Runtime::require(
            in_array($config['Cmd'] ?? null, [null, []], true),
            'Worker target inherited web command',
        );
        Runtime::require(
            ($config['Healthcheck']['Test'] ?? null) === ['NONE'],
            'Worker target inherited web healthcheck',
        );
        Runtime::require(
            in_array('APP_ENV=prod', $config['Env'] ?? [], true) && in_array(
                'APP_DEBUG=0',
                $config['Env'] ?? [],
                true,
            ),
            'Worker target must default to production without debug',
        );
        Runtime::require(empty($config['Volumes']), 'Worker target declares host volumes');
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        Runtime::require($arguments === [], 'Production worker qualification takes no arguments');
        $runtime = new Runtime($root, 300);
        $resources = new Resources($runtime, 'worker-production');
        try {
            $docker = $resources->docker;
            $image = $runtime->environment['BAANDER_TEST_IMAGE'] ?? '';
            Runtime::require($image !== '', 'Set BAANDER_TEST_IMAGE to an image built with --target worker');
            $identity = $docker->run(['image', 'inspect', '--format', '{{.Id}}', $image])->stdout
                |> trim(...);
            $information = json_decode(
                $docker->run(['image', 'inspect', $identity])->stdout,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            self::validateConfiguration($information[0]['Config']);
            $resources->containers = [
                $resources->id . '-runtime',
                $resources->id . '-platform',
                $resources->id . '-file',
            ];
            $docker->run([
                'run',
                '--rm',
                '--name',
                $resources->id . '-runtime',
                '--network',
                'none',
                '--entrypoint',
                '/usr/local/bin/php',
                $identity,
                '-r',
                self::RUNTIME_PROBE,
            ]);
            $docker->run([
                'run',
                '--rm',
                '--name',
                $resources->id . '-platform',
                '--network',
                'none',
                '--entrypoint',
                '/usr/bin/composer',
                $identity,
                'check-platform-reqs',
                '--no-dev',
            ]);
            $docker->run([
                'run',
                '--rm',
                '--name',
                $resources->id . '-file',
                '--network',
                'none',
                '--cap-drop',
                'ALL',
                '--security-opt',
                'no-new-privileges',
                '--entrypoint',
                '/usr/local/bin/php',
                $identity,
                '-r',
                self::FILE_PROBE,
            ]);
            $runtime->run(
                [PHP_BINARY, 'scripts/test-worker-operator-container.php'],
                env: array_replace(
                    $runtime->environment,
                    [
                        'BAANDER_TEST_IMAGE' => $identity,
                        'BAANDER_TEST_USE_IMAGE' => '1',
                        'BAANDER_TEST_CHECKOUT_IN_IMAGE' => '1',
                        'BAANDER_TEST_OPERATOR_IN_CONTAINER' => '1',
                    ],
                ),
                timeout: null,
                interactive: true,
                terminationGrace: 40,
            );
            return 0;
        } finally {
            $resources->close();
        }
    }

    private const string RUNTIME_PROBE = <<<'PHP_CODE'
        require 'vendor/autoload.php';
        if (is_dir('vendor/nelmio/api-doc-bundle/public') || is_dir('/home/www-data/.cache/composer')) {
            throw new RuntimeException('Production image contains documentation assets or Composer cache');
        }
        if (!in_array(ini_get('display_errors'), ['', '0'], true) || !in_array(ini_get('display_startup_errors'), ['', '0'], true)) {
            throw new RuntimeException('Production PHP displays runtime or startup errors');
        }
        if (ini_get('memory_limit') !== '512M') {
            throw new RuntimeException('Production PHP memory_limit must be 512M');
        }
        if (!is_file('vendor/autoload_runtime.php') || Composer\InstalledVersions::isInstalled('phpunit/phpunit')
            || Composer\InstalledVersions::isInstalled('symfony/web-profiler-bundle') || is_dir('config/secrets')
            || is_file('docker/dev/juul.localdomain.key') || is_dir('.reli')) {
            throw new RuntimeException('Production image contains development artifacts or lacks runtime autoloader');
        }
        PHP_CODE;
    private const string FILE_PROBE = <<<'PHP_CODE'
        Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        Swoole\Coroutine\run(static function (): void {
            $path = tempnam(sys_get_temp_dir(), 'baander-file-probe-');
            if ($path === false) { throw new RuntimeException('Cannot create production file probe'); }
            try {
                if (file_put_contents($path, 'worker file I/O') !== 15 || file_get_contents($path) !== 'worker file I/O') {
                    throw new RuntimeException('Production coroutine file I/O failed');
                }
            } finally { unlink($path); }
        });
        PHP_CODE;
}
