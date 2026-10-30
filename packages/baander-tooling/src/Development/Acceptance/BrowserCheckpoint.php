<?php

declare(strict_types=1);

namespace Baander\Tooling\Development\Acceptance;

use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Process\{EngineExecutor, ExecutionContext, ProcessSpec};
use Baander\Tooling\Resources\{ResourceKind, ResourceLedger};

final class BrowserCheckpoint
{
    /** @param array<string,string> $environment */
    public function __construct(
        private readonly Client $docker,
        private readonly ToolingConfiguration $configuration,
        private readonly array $environment,
        private readonly string $repository,
    ) {}

    /** Recovery deliberately does not resolve the current image or Docker configuration.
     * @param array<string,string> $environment
     */
    public static function cleanup(string $artifacts, array $environment = []): int
    {
        $marker = $artifacts . '/browser-container';
        if (!is_file($marker)) {
            return 0;
        }
        $status = 0;
        $executor = null;
        try {
            $state = AtomicFile::readJson($marker);
            $budget = AtomicFile::readJson($artifacts . '/browser-budget.json')['cleanup'];
            $context = ExecutionContext::bounded($budget, $environment, ignoreCancellation: true);
            $executor = new EngineExecutor($context);
            $docker = new Client($executor, Target::fromArray($state['target']), $environment);
            $docker->validateIdentity();
            if (!is_dir($artifacts . '/browser')) {
                mkdir($artifacts . '/browser', 0700, true);
            }
            foreach ($state['resources'] as $resource) {
                $inspection = $docker->run(['container', 'inspect', '--', $resource['name']], check: false);
                if ($inspection->exitCode === 0) {
                    $observed = json_decode($inspection->stdout, true, flags: JSON_THROW_ON_ERROR)[0];
                    Support::check(
                        ($observed['Config']['Labels']['org.baander.tooling.run'] ?? null) === $state['token']
                        && ($resource['id'] === null || ($observed['Id'] ?? null) === $resource['id']),
                        'Browser ownership changed; preserving the receipt.',
                    );
                    $copy = $docker->run(['cp', ($resource['id'] ?? $resource['name']) . ':/work/evidence/.', $artifacts . '/browser'], check: false);
                    if ($copy->exitCode !== 0) {
                        $status = 1;
                    }
                }
            }
            $report = new ResourceLedger($marker, $docker, $environment)->cleanup($context->remaining());
            if (!$report->successful()) {
                fwrite(STDERR, $report->message() . "\n");
                $status = 1;
            }
        } catch (\Throwable $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
            $status = 1;
        } finally {
            $executor?->close();
        }
        if ($status !== 0) {
            fwrite(STDERR, "Browser evidence capture or container cleanup could not be verified.\n");
        }
        return $status;
    }

    public function run(string $phase, string $artifacts, string $baseUrl): int
    {
        if ($phase === 'cleanup') {
            return self::cleanup($artifacts, $this->environment);
        }
        $engine = $this->environment['BAANDER_TEST_BROWSER_ENGINE'] ?? '';
        $engine = $engine === '' ? 'chromium' : $engine;
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
            $this->docker->assertLocalPublishedPorts();
            $selection = $this->configuration->images->role('browser');
            $package = AtomicFile::readJson($this->repository . '/ui/web/node_modules/playwright-core/package.json');
            $selection->assertPlaywrightVersion($package['version'] ?? '');
            $image = $this->docker->resolveImage($selection);
            AtomicFile::writeJson($artifacts . '/browser-budget.json', ['cleanup' => $this->configuration->budgets->seconds('cleanup')]);
            new ResourceLedger($marker, $this->docker, $this->environment)->create(ResourceKind::Container, $container, [
                '--network',
                'host',
                '--memory=2g',
                '--pids-limit=256',
                '-e',
                'BAANDER_TEST_BROWSER_ENGINE=' . $engine,
                '--entrypoint',
                'node',
                $image->id,
                '/work/browser.mjs',
            ]);
            $workspace = Support::temporary('baander-browser-checkpoint.');
            try {
                mkdir($workspace . '/node_modules', 0700);
                $this->docker->executor->run(new ProcessSpec([
                    'cp',
                    '-R',
                    $this->repository . '/ui/web/node_modules/playwright-core',
                    $workspace . '/node_modules/playwright-core',
                ], environment: $this->environment, processGroup: true, replaceEnvironment: true));
                Support::check(
                    copy($this->repository . '/ui/web/tests/e2e/two-command/browser.mjs', $workspace . '/browser.mjs'),
                    'Cannot copy browser probe.',
                );
                $this->docker->run(['cp', $workspace, $container . ':/work']);
            } finally {
                Support::removeTree($workspace);
            }
            $this->docker->run(['start', $container]);
            for ($attempt = 0; $attempt < 100; $attempt++) {
                if ($this->docker->run(
                    ['exec', $container, 'test', '-s', '/work/browser-port'],
                    check: false,
                )->exitCode === 0) {
                    break;
                }
                usleep(100000);
            }
        }
        $receipt = AtomicFile::readJson($marker);
        $container = $receipt['resources'][0]['id'] ?? $receipt['resources'][0]['name'];
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
        $result = $this->docker->run(
            ['exec', '-i', $container, 'node', '--input-type=module', '-e', $javascript],
            input: $body,
            timeout: $this->configuration->budgets->seconds('browser'),
            check: false,
        );
        fwrite(STDOUT, $result->stdout);
        fwrite(STDERR, $result->stderr);
        return $result->exitCode;
    }
}
