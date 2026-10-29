<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Throwable;
use Baander\Tooling\Resources\DisposableContainer;

final class OperatorCleanup
{
    /** @var list<string> */
    private array $errors = [];
    public function __construct(private readonly OperatorCommands $commands, private readonly OperatorRun $state, private readonly OperatorResources $resources) {}

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @param list<string> $arguments */
    private function docker(array $arguments, ?string $log = null, bool $required = true): ?\Baander\Tooling\Process\ProcessResult
    {
        try {
            return $this->commands->docker($arguments, log: $log, timeout: 5, cleanup: true);
        } catch (Throwable $error) {
            if ($required) {
                $this->errors[] = $error->getMessage();
            }
            return null;
        }
    }

    private function diagnostic(): void
    {
        $s = $this->state;
        $artifacts = $s->artifacts;
        if (($s->faultContainer) !== '') {
            $this->docker(['unpause', $s->faultContainer]);
        }
        if ($s->twoCommand || $s->lifecyclePhase !== '') {
            $this->docker(['logs', $s->runId . '-postgres'], $artifacts . '/postgres.log', false);
            $this->docker(
                ['inspect', '--format', '{{json .State}}', $s->runId . '-postgres'],
                $artifacts . '/postgres-state.json',
                false,
            );
            foreach (['web', 'worker', 'control'] as $role) {
                $name = $s->{$role . 'Name'} ?? '';
                if ($name === '') {
                    continue;
                }
                $this->docker(['logs', $name], $artifacts . '/' . $role . '.log', false);
                $this->docker(
                    ['inspect', '--format', '{{json .State}}', $name],
                    $artifacts . '/' . $role . '-state.json',
                    false,
                );
                $directory = $artifacts . '/' . $role . '-logs';
                if (!is_dir($directory)) {
                    mkdir($directory, 0700, true);
                }
                $this->docker(['cp', $name . ':' . $s->appHome . '/var/log/.', $directory], required: false);
            }
            $this->docker(
                [
                    'exec',
                    $s->runId . '-postgres',
                    'psql',
                    '-U',
                    'baander',
                    '-d',
                    'worker_operator_test',
                    '-c',
                    'SELECT job_id, generation, outcome, error FROM transcode_runtime_state',
                ],
                $artifacts . '/transcode-runtime.txt',
                false,
            );
        }
        $hook = $this->commands->environment['BAANDER_TEST_TWO_COMMAND_CHECKPOINT'] ?? '';
        if ($s->twoCommand && $hook !== '') {
            try {
                $this->commands->run([$hook, 'cleanup', $artifacts, $s->webBase], timeout: 30, cleanup: true);
            } catch (Throwable $error) {
                $this->errors[] = $error->getMessage();
            }
        }
        if ($s->mediaObservation) {
            $this->docker(
                [
                    'cp',
                    $s->webName . ':' . $s->appHome . '/storage/two-command/native-launches.jsonl',
                    $artifacts . '/native-launches.jsonl',
                ],
                required: false,
            );
        }
    }

    /** @return array<string,array{id:string,namespace:string,role:string}>|null */
    private function inventory(string $phase): ?array
    {
        $arguments = [
            'container', 'ls', '--all', '--no-trunc', '--format',
            '{{.ID}}|{{.Names}}|{{.Label "app.baander.worker.namespace"}}|{{.Label "app.baander.worker.role"}}',
        ];
        try {
            $result = $this->commands->docker(
                $arguments,
                check: false,
                stdout: $this->state->artifacts . '/cleanup-' . $phase . '-container.log',
                timeout: 5,
                cleanup: true,
            );
            $log = $this->state->artifacts . '/cleanup-' . $phase . '-container.log';
            $output = file_get_contents($log);
            if ($output === false) {
                throw new \RuntimeException('Cannot read cleanup inventory log');
            }
            file_put_contents($log, $output . (file_get_contents($log . '.stderr') ?: ''));
            if ($result->exitCode !== 0) {
                throw new \RuntimeException('Cleanup could not inventory operator containers');
            }
            $resources = [];
            foreach (explode("\n", trim($output)) as $line) {
                if ($line === '') {
                    continue;
                }
                [$id, $name, $namespace, $role] = array_pad(explode('|', $line), 4, '');
                $resources[$name] = ['id' => $id, 'namespace' => $namespace, 'role' => $role];
            }
            return $resources;
        } catch (Throwable $error) {
            file_put_contents(
                $this->state->artifacts . '/cleanup-' . $phase . '-container.log',
                $error->getMessage() . "\n",
                FILE_APPEND,
            );
            $this->errors[] = $error->getMessage();
            return null;
        }
    }

    /** @param array{id:string,namespace:string,role:string} $resource */
    private function namespaceOwned(array $resource): bool
    {
        return $this->state->namespace !== '' && $resource['namespace'] === $this->state->namespace
            && in_array($resource['role'], ['control', 'execution'], true);
    }

    public function run(int $originalStatus): int
    {
        $this->diagnostic();
        foreach ($this->inventory('before') ?? [] as $name => $resource) {
            if (!$this->namespaceOwned($resource)) {
                continue;
            }
            if (preg_match('/^[a-f0-9]{64}$/D', $resource['id']) !== 1) {
                $this->errors[] = 'Cannot identify owned operator container: ' . $name;
                continue;
            }
            $this->docker(['container', 'rm', '-f', '--volumes', $resource['id']]);
        }
        $source = $this->state->developmentSourcePath;
        if ($source !== '' && $source === '/tmp/' . $this->state->runId . '-source') {
            try {
                DisposableContainer::run(
                    $this->commands->client(cleanup: true),
                    $this->state->artifacts . '/source-cleanup-resource.json',
                    [
                        '--network',
                        'none',
                        '--user',
                        '0',
                        '--security-opt',
                        'label=disable',
                        '--mount',
                        'type=bind,source=/tmp,target=/host-tmp',
                        '--entrypoint',
                        '/bin/sh',
                        $this->state->baseId,
                        '-c',
                        'rm -rf -- "/host-tmp/$1"',
                        'sh',
                        $this->state->runId . '-source',
                    ],
                    $this->commands->environment,
                    $this->commands->runtime->configuration->budgets->seconds('cleanup'),
                );
            } catch (Throwable $error) {
                $this->errors[] = $error->getMessage();
            }
        }
        array_push($this->errors, ...$this->resources->cleanup());
        foreach ($this->inventory('after') ?? [] as $name => $resource) {
            if ($this->namespaceOwned($resource)) {
                $this->errors[] = 'Cleanup left owned operator container: ' . $name;
            }
        }
        try {
            $this->commands->run(['rm', '-rf', '--', $this->state->work], timeout: 5, cleanup: true);
        } catch (Throwable $error) {
            $this->errors[] = $error->getMessage();
        }
        $status = $originalStatus === 0 && $this->errors !== [] ? 1 : $originalStatus;
        if ($this->state->twoCommand || $this->state->lifecyclePhase !== '') {
            try {
                OperatorConfiguration::write(
                    $this->state->artifacts . '/cleanup.json',
                    [
                        'exitStatus' => $status,
                        'project' => $this->state->runId,
                        'originalExitStatus' => $originalStatus,
                        'cleanupExitStatus' => $this->errors === [] ? 0 : 1,
                        'cleanupVerified' => $this->errors === [],
                    ],
                );
            } catch (Throwable $error) {
                $this->errors[] = $error->getMessage();
                if ($status === 0) {
                    $status = 1;
                }
            }
            echo 'Combined drill artifacts: ' . $this->state->artifacts . "\n";
        }
        foreach ($this->errors as $error) {
            fwrite(STDERR, $error . "\n");
        }
        return $status;
    }
}
