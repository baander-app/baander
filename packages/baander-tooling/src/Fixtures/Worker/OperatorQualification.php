<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\Runtime as FixtureRuntime;
use RuntimeException;
use Throwable;

final class OperatorQualification
{
    public readonly OperatorRun $state;
    public readonly OperatorConfiguration $configuration;
    public readonly OperatorCommands $commands;
    public readonly OperatorResources $resources;
    public readonly OperatorTopology $topology;
    public readonly OperatorProvisioning $provisioning;
    public readonly OperatorScenario $scenario;

    /** @param array<string,string> $environment */
    public function __construct(public readonly string $root, array $environment)
    {
        $this->commands = new OperatorCommands($root, $environment);
        $this->configuration = new OperatorConfiguration($environment, $this->commands->runtime->configuration);
        $client = $this->commands->client();
        $client->assertLocalPublishedPorts();
        $this->topology = new OperatorTopology($client->target, $this->configuration->container, $environment);
        $runId = 'baander-worker-operator-' . time() . '-' . getmypid();
        $work = sys_get_temp_dir() . '/baander-worker-operator.' . bin2hex(random_bytes(8));
        $artifacts = $environment['BAANDER_TEST_ARTIFACTS'] ?? $root . '/var/test-runs/' . $runId;
        if (!str_starts_with($artifacts, '/')) {
            $artifacts = $root . '/' . $artifacts;
        }
        if (!is_dir($artifacts) && !mkdir($artifacts, 0700, true)) {
            throw new RuntimeException('Cannot create worker qualification artifacts');
        }
        chmod($artifacts, 0700);
        if (!mkdir($work, 0700)) {
            throw new RuntimeException('Cannot create worker qualification workspace');
        }
        $this->state = new OperatorRun();
        $this->state->runId = $runId;
        $this->state->work = $work;
        $this->state->artifacts = $artifacts;
        $this->state->webName = $runId . '-web';
        $this->state->workerName = '';
        $this->state->controlName = '';
        $this->state->webBase = '';
        $this->state->fixtureImage = $runId . ':fixture';
        $this->state->fixtureBase = $runId . ':base';
        $this->state->controllerImage = $runId . ':controller';
        $this->state->controllerCli = $runId . ':cli';
        $this->state->sourceVolume = $runId . '-source-media';
        $this->state->storageVolume = $runId . '-storage';
        $this->state->stateVolume = $runId . '-media-state';
        $this->state->secretsVolume = $runId . '-web-secrets';
        $this->state->ipcVolume = $runId . '-worker-ipc';
        $this->state->twoCommand = $this->configuration->twoCommand;
        $this->state->lifecyclePhase = $this->configuration->lifecyclePhase;
        $this->state->mediaObservation = $this->configuration->mediaObservation;
        $this->state->faultContainer = '';
        $this->state->namespace = '';
        $this->state->baseId = '';
        $this->commands->exportTarget($this->artifact('docker-target.json'));
        $this->resources = new OperatorResources($this->commands, $artifacts);
        $this->provisioning = new OperatorProvisioning($this);
        $this->scenario = new OperatorScenario($this);
    }

    /** @param list<string> $arguments */
    public function docker(
        array $arguments,
        bool $check = true,
        ?string $input = null,
        ?string $log = null,
        ?string $stdout = null,
        ?string $stderr = null,
    ): \Baander\Tooling\Process\ProcessResult {
        return $this->commands->docker($arguments, $check, $input, $log, $stdout, $stderr);
    }

    /** @param list<string> $arguments */
    public function output(array $arguments): string
    {
        return trim($this->docker($arguments)->stdout);
    }
    public function work(string $name): string
    {
        return $this->state->work . '/' . $name;
    }
    public function artifact(string $name): string
    {
        return $this->state->artifacts . '/' . $name;
    }
    public function copy(string $from, string $to): void
    {
        OperatorConfiguration::require(copy($from, $to), 'Cannot copy evidence: ' . $from);
    }
    public function read(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read evidence: ' . $path);
        }
        return $contents;
    }
    public function write(string $path, string $value): void
    {
        OperatorConfiguration::require(file_put_contents($path, $this->application($value)) !== false, 'Cannot write: ' . $path);
    }
    public function application(string $value): string
    {
        return str_replace('%APP_HOME%', $this->state->appHome, $value);
    }

    public function expect(bool $condition, string $message): void
    {
        OperatorConfiguration::require($condition, $message);
    }

    public function boot(string $boot, bool $rename = false): void
    {
        $this->state->boot = $boot;
        $manifest = OperatorConfiguration::json($this->work('manifest.json'));
        $manifest['bootId'] = $boot;
        OperatorConfiguration::write($this->work('manifest.json'), $manifest);
        $this->publishManifest();
        if ($rename) {
            $this->state->workerName = OperatorConfiguration::workerName($this->state->namespace, $boot);
            $this->state->controlName = substr($this->state->workerName, 0, -strlen('execution')) . 'control';
        }
    }

    public function publishManifest(): void
    {
        if ($this->configuration->container) {
            $this->docker(
                [
                    'exec',
                    '-i',
                    $this->state->runId . '-controller',
                    '/bin/sh',
                    '-c',
                    'umask 077; cat > /tmp/operator/manifest.json',
                ],
                input: $this->read($this->work('manifest.json')),
            );
        }
    }

    public function operator(string $action, int $expected): void
    {
        $arguments = $this->configuration->container
            ? [
                'exec',
                '-e',
                'BAANDER_TOOLING_TARGET_FILE=/tmp/operator/target.json',
                $this->state->runId . '-controller',
                '/usr/local/bin/php',
                $this->application('%APP_HOME%/bin/worker-deployment.php'),
                $action,
                '/tmp/operator/manifest.json',
                '/tmp/operator/credentials.json',
            ]
            : [
                $this->configuration->environment['BAANDER_TEST_PHP_BINARY'] ?? PHP_BINARY,
                'bin/worker-deployment.php',
                $action,
                $this->work('manifest.json'),
                $this->work('credentials.json'),
            ];
        $result = $this->configuration->container
            ? $this->commands->docker($arguments, check: false, stdout: $this->work($action . '.json'), stderr: $this->work($action . '.stderr'))
            : $this->commands->run(
                $arguments,
                check: false,
                stdout: $this->work($action . '.json'),
                stderr: $this->work($action . '.stderr'),
                environment: array_replace($this->configuration->environment, ['APP_HOME' => $this->state->appHome]),
            );
        $this->expect(
            $result->exitCode === $expected,
            'Operator ' . $action . ' expected exit ' . $expected . ', got ' . $result->exitCode . "\n"
            . $this->read($this->work($action . '.json'))
            . $this->read($this->work($action . '.stderr')),
        );
        $frame = OperatorConfiguration::json($this->work($action . '.json'));
        $this->expect(
            ($frame['action'] ?? null) === $action && ($frame['success'] ?? null) === ($expected === 0),
            'Operator response does not match action/outcome',
        );
    }

    /** @return array<string,mixed> */
    public function result(string $action = 'status'): array
    {
        return OperatorConfiguration::json($this->work($action . '.json'))['result'];
    }

    public function awaitCheck(string $mode, ?string $container = null): void
    {
        $container ??= str_starts_with($mode, 'ready') ? $this->state->controlName : $this->state->workerName;
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $result = $this->docker(
                [
                    'exec',
                    $container,
                    'php',
                    '-d',
                    'memory_limit=64M',
                    'tests/Fixtures/Worker/worker-command-check.php',
                    $mode,
                    $this->state->namespace,
                    $this->state->boot,
                ],
                false,
                log: $this->artifact($mode . '.log'),
            );
            if ($result->exitCode === 0) {
                return;
            }
            if ($this->output(['inspect', '--format', '{{.State.Running}}', $this->state->workerName]) !== 'true') {
                break;
            }
            $this->commands->pause(0.2);
        }
        try {
            $this->docker(
                ['logs', '--tail', '60', $this->state->workerName],
                false,
                log: $this->artifact($mode . '-worker.log'),
            );
        } catch (Throwable $error) {
            fwrite(STDERR, 'Worker log collection failed: ' . $error->getMessage() . "\n");
        }
        throw new RuntimeException('Actual application worker check ' . $mode . ' failed; see ' . $this->artifact($mode . '.log'));
    }

    public function checkpoint(string $phase): void
    {
        $hook = $this->configuration->environment['BAANDER_TEST_TWO_COMMAND_CHECKPOINT'] ?? '';
        if ($hook !== '') {
            $this->commands->run(
                [$hook, $phase, $this->state->artifacts, $this->state->webBase],
                terminationGrace: 40,
            );
        }
    }

    public function snapshot(string $role, string $name, string $label): void
    {
        $this->docker(
            ['exec', $name, 'php', 'tests/Fixtures/TwoCommand/processes.php', $role],
            stdout: $this->artifact($label . '-processes.json'),
        );
    }

    public function hostIdentities(string $mode, string $input, string $output): void
    {
        $this->resources->runContainer(
            [
                '-i',

                '--pid',
                'host',
                '--network',
                'none',
                '--cap-drop',
                'ALL',
                '--security-opt',
                'no-new-privileges',
                '--entrypoint',
                '/usr/local/bin/php',
                $this->state->imageId,
                'tests/Fixtures/TwoCommand/identities.php',
                $mode,
            ],
            input: $this->read($input),
            stdout: $output,
        );
    }

    public function combinedPhase(string $phase): void
    {
        $result = $this->resources->runContainer(
            [

                '--network',
                'container:' . $this->state->webName,
                '--cap-drop',
                'ALL',
                '--security-opt',
                'no-new-privileges',
                '--env-file',
                $this->work('runtime.env'),
                ...$this->state->mediaMounts,
                '--mount',
                'type=volume,source=' . $this->state->secretsVolume . $this->application(',target=%APP_HOME%/config/secrets,readonly,volume-nocopy'),
                '--entrypoint',
                '/usr/local/bin/php',
                $this->state->imageId,
                'tests/Fixtures/TwoCommand/deployment.php',
                $phase,
            ],
            false,
            stdout: $this->artifact($phase . '.stdout.log'),
            stderr: $this->artifact($phase . '.stderr'),
        );
        if ($result->exitCode !== 0) {
            $this->docker(
                ['exec', $this->state->webName, 'cat', $this->application('%APP_HOME%/var/log/swoole_prod.log')],
                false,
                stdout: $this->artifact('swoole.log'),
            );
            throw new RuntimeException('Combined phase failed: ' . $phase . "\n" . $this->read($this->artifact($phase . '.stderr')));
        }
        $this->docker([
            'cp',
            $this->state->webName . $this->application(':%APP_HOME%/storage/two-command/result-') . $phase . '.json',
            $this->artifact($phase . '.json'),
        ]);
        $receipt = json_decode($this->read($this->artifact($phase . '.json')), flags: JSON_THROW_ON_ERROR);
        $this->expect(
            $receipt instanceof \stdClass && ($receipt->phase ?? null) === $phase
            && (is_int($receipt->seconds ?? null) || is_float($receipt->seconds ?? null))
            && $receipt->seconds >= 0
            && ($receipt->evidence ?? null) instanceof \stdClass,
            'Invalid phase receipt',
        );
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        if ($arguments !== []) {
            throw new \InvalidArgumentException('This drill does not accept arguments');
        }
        $qualification = new self($root, getenv());
        $status = 1;
        try {
            $qualification->scenario->run();
            $status = 0;
        } catch (Throwable $error) {
            $status = FixtureRuntime::status($error);
            fwrite(STDERR, FixtureRuntime::failure($error) . "\n");
        } finally {
            try {
                $status = new OperatorCleanup($qualification->commands, $qualification->state, $qualification->resources)->run($status);
            } catch (Throwable $error) {
                fwrite(STDERR, 'Cleanup failed: ' . $error->getMessage() . "\n");
                if ($status === 0) {
                    $status = 1;
                }
            }
            $qualification->commands->close();
        }
        return $status;
    }


}
