<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Resources\{ResourceLedger, ResourceKind, DisposableContainer, ImageLedger};
use Baander\Tooling\Process\ProcessResult;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Fixtures\Runtime;

final class OperatorResources
{
    private readonly ResourceLedger $ledger;
    private ?ImageLedger $images = null;

    public function __construct(private readonly OperatorCommands $commands, private readonly string $artifacts)
    {
        $this->ledger = new ResourceLedger($artifacts . '/resources.json', $commands->client(), $commands->environment);
        if (is_file($artifacts . '/images.json')) {
            $this->images = new ImageLedger($artifacts . '/images.json', $commands->client());
        }
    }

    /** @param list<string> $arguments Docker create options, image and container command. */
    public function createContainer(string $name, array $arguments): string
    {
        return $this->ledger->create(ResourceKind::Container, $name, $arguments);
    }

    /** @param list<string> $arguments Docker create options, image and container command. */
    public function startContainer(
        string $name,
        array $arguments,
        bool $check = true,
        ?string $input = null,
        ?string $log = null,
        ?string $stdout = null,
        ?string $stderr = null,
        bool $attached = false,
    ): ProcessResult {
        $id = $this->createContainer($name, $arguments);
        return $this->commands->client()->run(
            ['start', ...($attached ? ['--attach'] : []), ...($input !== null ? ['--interactive'] : []), $id],
            input: $input,
            check: $check,
            logPath: $log ?? $stdout,
            stderrLogPath: $stderr ?? ($stdout === null ? null : $stdout . '.stderr'),
        );
    }

    /** @param list<string> $arguments Docker create options, image and container command. */
    public function runContainer(
        array $arguments,
        bool $check = true,
        ?string $input = null,
        ?string $log = null,
        ?string $stdout = null,
        ?string $stderr = null,
    ): ProcessResult {
        return DisposableContainer::run(
            $this->commands->client(),
            $this->artifacts . '/probe-' . bin2hex(random_bytes(8)) . '.json',
            $arguments,
            $this->commands->environment,
            $this->commands->runtime->configuration->budgets->seconds('cleanup'),
            input: $input,
            check: $check,
            logPath: $log ?? $stdout,
            stderrLogPath: $stderr ?? ($stdout === null ? null : $stdout . '.stderr'),
        );
    }

    /** @param list<string> $arguments */
    public function createNetwork(string $name, array $arguments = []): string
    {
        return $this->ledger->create(ResourceKind::Network, $name, $arguments);
    }

    /** @param list<string> $arguments */
    public function createVolume(string $name, array $arguments = []): string
    {
        return $this->ledger->create(ResourceKind::Volume, $name, $arguments);
    }

    public function buildInput(string $role, string $id): string
    {
        $docker = $this->commands->client();
        $images = $this->images ??= new ImageLedger($this->artifacts . '/images.json', $docker);
        $path = $this->artifacts . '/build-inputs.json';
        $evidence = is_file($path) ? AtomicFile::readJson($path) : ['version' => 1, 'daemonId' => $docker->target->daemonId, 'inputs' => []];
        if (($evidence['version'] ?? null) !== 1 || ($evidence['daemonId'] ?? null) !== $docker->target->daemonId || !is_array($evidence['inputs'] ?? null)
            || array_key_exists($role, $evidence['inputs'])) {
            throw new \RuntimeException('Invalid or duplicate build input evidence; preserve it for inspection.');
        }
        $evidence['inputs'][$role] = ['id' => $id];
        AtomicFile::writeJson($path, $evidence);
        $reference = $images->alias($role, $id);
        $evidence['inputs'][$role]['reference'] = $reference;
        AtomicFile::writeJson($path, $evidence);
        return $reference;
    }

    /** @param list<string> $arguments Docker build options and context, without tag or ownership flags. */
    public function buildImage(string $name, array $arguments, string $log): ProcessResult
    {
        $docker = $this->commands->client();
        $images = $this->images ??= new ImageLedger($this->artifacts . '/images.json', $docker);
        $images->validateAliases($docker);
        $images->intend($name);
        $failure = null;
        try {
            $result = $docker->run(['build', '--tag', $name, '--label', 'org.baander.tooling.run=' . $images->token, ...$arguments], logPath: $log);
        } catch (\Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            try {
                $images->validateAliases($docker);
            } catch (\Throwable $verification) {
                if ($failure !== null) {
                    throw new \RuntimeException(Runtime::failure($failure) . "\nBuild input verification failed: " . $verification->getMessage(), Runtime::status($failure), $failure);
                }
                throw $verification;
            }
        }
        $images->observe($name);
        return $result;
    }

    /** @return list<string> */
    public function cleanup(): array
    {
        $errors = [];
        try {
            $remaining = $this->commands->runtime->cleanupExecutor()->context->remaining()
                ?? $this->commands->runtime->configuration->budgets->seconds('cleanup');
            array_push($errors, ...$this->ledger->cleanup($remaining)->errors);
        } catch (\Throwable $error) {
            $errors[] = $error->getMessage() . '; retained resource receipt: ' . $this->ledger->receipt;
        }
        array_push($errors, ...($this->images?->cleanup($this->commands->client(cleanup: true))->errors ?? []));
        return $errors;
    }
}
