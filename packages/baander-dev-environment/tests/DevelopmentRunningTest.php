<?php

declare(strict_types=1);

namespace Baander\DevEnvironment\Tests;

use Baander\DevEnvironment\Runtime\ComposeRuntime;
use Baander\DevEnvironment\State\InstanceStateStore;
use Baander\DevEnvironment\WorkflowError;
use Baander\Process\Execution\ProcessResult;
use Baander\Tests\Tooling\Support\DevelopmentCommandExecutor;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

final class DevelopmentRunningTest extends TestCase
{
    /** @param list<array<string,mixed>> $containers */
    private function observe(array $containers, string $kind = 'persistent'): bool
    {
        $runner = new class ($containers) extends DevelopmentCommandExecutor {
            /** @param list<array<string,mixed>> $containers */
            public function __construct(private readonly array $containers) {}
            public function execute(
                array $arguments,
                ?string $cwd = null,
                ?array $env = null,
                ?string $input = null,
                ?float $timeout = 120,
                bool $check = true,
                ?string $logPath = null,
                bool $interactive = false,
            ): ProcessResult {
                $output = in_array('info', $arguments, true) ? '{"ID":"daemon"}' : (in_array('inspect', $arguments, true)
                    ? json_encode($this->containers, JSON_THROW_ON_ERROR) : implode("\n", array_keys($this->containers)));
                return new ProcessResult(0, null, false, false, $output, '', 0);
            }
        };
        $compose = tempnam(sys_get_temp_dir(), 'baander-running-');
        file_put_contents($compose, json_encode(['services' => ['log-agent' => ['image' => 'owned']]], JSON_THROW_ON_ERROR));
        try {
            return new ComposeRuntime(new InstanceStateStore('/tmp/baander-unused'), $runner)->running([
                'project' => 'owned',
                'kind' => $kind,
                'docker' => DevelopmentCommandExecutor::target('fake', 'owned', 'daemon'),
                'compose_file' => $compose,
            ]);
        } finally {
            unlink($compose);
        }
    }

    /** @return list<array<string,mixed>> */
    private function containers(): array
    {
        return array_map(
            static fn(string $role): array => [
                'Config' => ['Labels' => ['com.docker.compose.project' => 'owned', 'com.docker.compose.service' => $role]],
                'State' => ['Running' => true, 'Health' => ['Status' => 'unhealthy']],
            ],
            ['app', 'nginx', 'postgres', 'redis', 'log-agent', 'vite'],
        );
    }

    public function testSavedComposeWithoutCollectorRequestsReactivationBeforeSelectingNewService(): void
    {
        $runner = new class extends DevelopmentCommandExecutor {
            public function execute(
                array $arguments,
                ?string $cwd = null,
                ?array $env = null,
                ?string $input = null,
                ?float $timeout = 120,
                bool $check = true,
                ?string $logPath = null,
                bool $interactive = false,
            ): ProcessResult {
                Assert::assertNotContains('compose', $arguments, 'A stale topology must be rendered before selecting new services.');
                return new ProcessResult(0, null, false, false, '{"ID":"daemon"}', '', 0);
            }
        };
        $compose = tempnam(sys_get_temp_dir(), 'baander-running-');
        file_put_contents($compose, '{"services":{"app":{"image":"retained-application"}}}');
        try {
            self::assertFalse(new ComposeRuntime(new InstanceStateStore('/tmp/baander-unused'), $runner)->running([
                'project' => 'owned', 'kind' => 'preview',
                'docker' => DevelopmentCommandExecutor::target('fake', 'owned', 'daemon'), 'compose_file' => $compose,
            ]));
        } finally {
            unlink($compose);
        }
    }

    public function testRunningContainersAreNotRestartedForUnhealthyFunctionalStatus(): void
    {
        self::assertTrue($this->observe($this->containers(), 'preview'));
        array_slice($this->containers(), 0, 5) |> $this->observe(...) |> self::assertTrue(...);
    }

    public function testMissingOrStoppedContainersAreObserved(): void
    {
        self::assertFalse($this->observe([]));
        foreach (array_keys($this->containers()) as $index) {
            $missing = $this->containers();
            array_splice($missing, $index, 1);
            self::assertFalse($this->observe($missing, 'preview'));
            $stopped = $this->containers();
            $stopped[$index]['State']['Running'] = false;
            self::assertFalse($this->observe($stopped, 'preview'));
        }
    }

    public function testWrongOwnerDuplicateAndNonBooleanStateAreRefused(): void
    {
        foreach (['owner', 'duplicate', 'state'] as $case) {
            $containers = $this->containers();
            if ($case === 'owner') {
                $containers[0]['Config']['Labels']['com.docker.compose.project'] = 'other';
            } elseif ($case === 'duplicate') {
                $containers[0]['Config']['Labels']['com.docker.compose.service'] = 'nginx';
            } else {
                $containers[0]['State']['Running'] = 'true';
            }
            try {
                $this->observe($containers, 'preview');
                self::fail('Expected invalid observation refusal');
            } catch (WorkflowError $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }
}
