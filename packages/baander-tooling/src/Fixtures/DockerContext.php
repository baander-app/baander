<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures;

final class DockerContext
{
    private const array EXCLUDED = [
        '.git/sentinel',
        '.github/sentinel',
        '.claude/sentinel',
        '.agents/sentinel',
        '.codex/sentinel',
        '.rpiv/sentinel',
        '.reli/sentinel',
        '.idea/sentinel',
        'ui/rn/android/.idea/sentinel',
        'ui/rn/android/.claude/sentinel',
        '.phpunit.cache/sentinel',
        '.deptrac.cache',
        'node_modules/sentinel',
        'ui/rn/node_modules/sentinel',
        'ui/web/node_modules/sentinel',
        'packages/local/node_modules/sentinel',
        'ui/rn/.yarn/install-state.gz',
        'vendor/sentinel',
        'var/cache/sentinel',
        'config/secrets/sentinel',
        'docker/dev/juul.localdomain.key',
        'auth.json',
        '.env.local',
        '.env.prod.local',
        '.env.local.php',
        'ui/rn/android/build/sentinel',
        'ui/rn/android/app/build/sentinel',
        'ui/rn/android/app/.cxx/sentinel',
        'ui/rn/android/.gradle/sentinel',
        'ui/rn/android/.kotlin/sentinel',
        'ui/rn/ios/build/sentinel',
        'ui/rn/ios/DerivedData/sentinel',
        'ui/web/playwright-report/sentinel',
        'ui/web/test-results/sentinel',
    ];
    private const array INCLUDED = [
        'src/Shared/sentinel.php',
        'bin/console',
        'composer.json',
        'composer.lock',
        'packages/swoole-bundle/composer.json',
        'packages/swoole-bundle/LICENSE',
        'packages/baander-phpstan-rules/src/sentinel.php',
        'packages/tsduck-php-ffi/sentinel.php',
        'patches/sentinel.patch',
        'LICENSE.md',
        'public/build/sentinel.js',
        'public/assets/sentinel.css',
        'public/dsp/sentinel.wasm',
        'ui/web/src/sentinel.tsx',
        'ui/web/tests/e2e/__screenshots__/capture-for-inspection.spec.ts',
        'ui/web/tests/e2e/characterization/__snapshots__/sentinel.png',
        'ui/rn/android/app/build.gradle',
        'ui/rn/android/app/src/main/sentinel.kt',
        'ui/rn/android/gradle/wrapper/gradle-wrapper.jar',
        'ui/rn/ios/Baander/sentinel.swift',
        'ui/rn/.yarn/patches/sentinel.patch',
        '.env',
        '.env.test',
        'docker/general/start-web.sh',
    ];

    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        Runtime::require($arguments === [], 'Docker context qualification takes no arguments');
        $runtime = new Runtime($root);
        $work = sys_get_temp_dir() . '/baander-docker-context-' . bin2hex(random_bytes(8));
        mkdir($work . '/context', 0700, true);
        try {
            copy($root . '/.dockerignore', $work . '/context/.dockerignore');
            file_put_contents($work . '/context/Dockerfile', "FROM scratch\nCOPY . /context/\n");
            foreach ([...self::EXCLUDED, ...self::INCLUDED] as $name) {
                $path = $work . '/context/' . $name;
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0700, true);
                }
                file_put_contents($path, "synthetic context marker\n");
            }
            $runtime->docker()->run(
                [
                    'buildx',
                    'build',
                    '--network=none',
                    '--progress=plain',
                    '--output',
                    'type=local,dest=' . $work . '/export',
                    $work . '/context',
                ],
                timeout: 600,
                logPath: $work . '/build.log',
            );
            foreach (self::EXCLUDED as $name) {
                Runtime::require(
                    !file_exists($work . '/export/context/' . $name),
                    'Host artifact entered Docker context: ' . $name,
                );
            }
            foreach (self::INCLUDED as $name) {
                Runtime::require(
                    is_file($work . '/export/context/' . $name),
                    'Required source or asset excluded: ' . $name,
                );
            }
            printf(
                "PASS: %d host artifacts excluded; %d source, asset and license markers preserved.\n",
                count(self::EXCLUDED),
                count(self::INCLUDED),
            );
            return 0;
        } catch (\Throwable $error) {
            if (is_file($work . '/build.log')) {
                $tail = file($work . '/build.log')
                    |> (static fn(array $lines): array => array_slice($lines, -40))
                    |> (static fn(array $lines): string => implode(
                        '',
                        $lines,
                    ));
                fwrite(STDERR, $tail);
            }
            throw $error;
        } finally {
            $runtime->cleaning = true;
            $runtime->run(['rm', '-rf', '--', $work]);
            $runtime->close();
        }
    }
}
