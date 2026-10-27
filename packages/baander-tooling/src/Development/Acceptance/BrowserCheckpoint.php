<?php

declare(strict_types=1);

namespace Baander\Tooling\Development\Acceptance;

final class BrowserCheckpoint
{
    public static function cleanup(string $artifacts): int
    {
        $marker = $artifacts . '/browser-container';
        if (!is_file($marker)) {
            return 0;
        }
        $container = (string) file_get_contents($marker)
            |> trim(...);
        $status = 0;
        if (!is_dir($artifacts . '/browser')) {
            mkdir($artifacts . '/browser', 0700, true);
        }
        $inventory = Support::run(
            ['docker', 'container', 'ls', '--all', '--format', '{{.Names}}'],
            check: false,
        );
        if ($inventory->exitCode !== 0) {
            $status = 1;
        } elseif (in_array($container, explode("\n", trim($inventory->stdout)), true)) {
            if (Support::run(
                ['docker', 'cp', $container . ':/work/evidence/.', $artifacts . '/browser'],
                check: false,
            )->exitCode !== 0) {
                $status = 1;
            }
        }
        Support::run(['docker', 'rm', '-f', $container], check: false);
        $inventory = Support::run(
            ['docker', 'container', 'ls', '--all', '--format', '{{.Names}}'],
            check: false,
        );
        if (
            $inventory->exitCode !== 0
            || in_array($container, explode("\n", trim($inventory->stdout)), true)
        ) {
            $status = 1;
        } else {
            unlink($marker);
        }
        if ($status !== 0) {
            fwrite(STDERR, "Browser evidence capture or container cleanup could not be verified.\n");
        }
        return $status;
    }

    public static function run(string $phase, string $artifacts, string $baseUrl): int
    {
        if ($phase === 'cleanup') {
            return self::cleanup($artifacts);
        }
        $engine = getenv('BAANDER_TEST_BROWSER_ENGINE') ?: 'chromium';
        if (!in_array($engine, ['chromium', 'firefox'], true)) {
            fwrite(STDERR, "BAANDER_TEST_BROWSER_ENGINE must be chromium or firefox.\n");
            return 64;
        }
        $marker = $artifacts . '/browser-container';
        if ($phase === 'authenticated') {
            fwrite(
                STDERR,
                "WARNING: Playback tests can produce audible tones or media through your speakers/headphones. Lower or mute system output before running; tests may change player volume.\n",
            );
            Support::check(!file_exists($marker), 'Browser checkpoint already owns a container.');
            $container = 'baander-two-command-browser-' . time() . '-' . getmypid();
            file_put_contents($marker, $container . "\n");
            Support::run([
                'docker',
                'create',
                '--name',
                $container,
                '--network',
                'host',
                '--memory=2g',
                '--pids-limit=256',
                '-e',
                'BAANDER_TEST_BROWSER_ENGINE=' . $engine,
                '--entrypoint',
                'node',
                'mcr.microsoft.com/playwright:v1.60.0-noble',
                '/work/browser.mjs',
            ]);
            $workspace = Support::temporary('baander-browser-checkpoint.');
            try {
                mkdir($workspace . '/node_modules', 0700);
                Support::run([
                    'cp',
                    '-R',
                    'ui/web/node_modules/playwright-core',
                    $workspace . '/node_modules/playwright-core',
                ]);
                Support::check(
                    copy('ui/web/tests/e2e/two-command/browser.mjs', $workspace . '/browser.mjs'),
                    'Cannot copy browser probe.',
                );
                Support::run(['docker', 'cp', $workspace, $container . ':/work']);
            } finally {
                Support::removeTree($workspace);
            }
            Support::run(['docker', 'start', $container]);
            for ($attempt = 0; $attempt < 100; $attempt++) {
                if (Support::run(
                    ['docker', 'exec', $container, 'test', '-s', '/work/browser-port'],
                    check: false,
                )->exitCode === 0) {
                    break;
                }
                usleep(100000);
            }
        }
        $container = (string) file_get_contents($marker)
            |> trim(...);
        $credentials = json_decode(
            (string) file_get_contents($artifacts . '/browser.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $body = json_encode(
            ['phase' => $phase, 'credentials' => $credentials, 'baseUrl' => $baseUrl],
            JSON_THROW_ON_ERROR,
        );
        $javascript = <<<'JS'
import { readFile } from "node:fs/promises";
let body = ""; for await (const chunk of process.stdin) body += chunk;
const phase = JSON.parse(body).phase;
const phaseTimeout = phase === "client-controls" ? 360000 : phase === "attached" ? 120000 : 90000;
const port = await readFile("/work/browser-port", "utf8");
const response = await fetch(`http://127.0.0.1:${port}`, {method:"POST", body, signal:AbortSignal.timeout(phaseTimeout)});
console.log(await response.text());
if (!response.ok) process.exitCode = 1;
JS;
        $result = Support::run(
            ['docker', 'exec', '-i', $container, 'node', '--input-type=module', '-e', $javascript],
            input: $body,
            timeout: 400,
            check: false,
        );
        fwrite(STDOUT, $result->stdout);
        fwrite(STDERR, $result->stderr);
        return $result->exitCode;
    }
}
