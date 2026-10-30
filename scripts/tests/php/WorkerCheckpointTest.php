<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Docker\Target;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Fixtures\Worker\{OperatorCommands, OperatorQualification, OperatorRun, OperatorScenario};
use Baander\Tooling\Inventory\Collector;
use PHPUnit\Framework\TestCase;

final class WorkerCheckpointTest extends TestCase
{
    public function testCheckpointReceivesActualWebIdentityAndCapturedTarget(): void
    {
        $directory = sys_get_temp_dir() . '/baander-worker-checkpoint-' . bin2hex(random_bytes(8));
        mkdir($directory . '/artifacts', 0700, true);
        $repository = dirname(__DIR__, 3);
        $state = new OperatorRun();
        $state->runId = 'baander-checkpoint';
        $state->artifacts = $directory . '/artifacts';
        $state->controlName = $state->runId . '-control';
        $state->workerName = $state->runId . '-execution';
        $state->ipcVolume = $state->runId . '-ipc';
        $state->webName = $state->runId . '-web-' . bin2hex(random_bytes(4));
        file_put_contents($directory . '/docker', '#!' . PHP_BINARY . "\n" . <<<'CLI'
<?php
$arguments = array_slice($argv, 1);
file_put_contents(getenv('CHECKPOINT_CALLS'), json_encode($arguments) . "\n", FILE_APPEND);
if (array_slice($arguments, 0, 2) !== ['--host', 'unix:///captured.baander.app.sock']) { exit(81); }
$arguments = array_slice($arguments, 2);
if ($arguments[0] === 'info') { echo '{"ID":"checkpoint-daemon"}'; exit; }
if ($arguments[0] === 'inspect' && in_array(getenv('CHECKPOINT_WEB'), $arguments, true)) { echo '[]'; exit; }
if (array_slice($arguments, 0, 2) === ['exec', getenv('CHECKPOINT_WEB')]) { exit; }
fwrite(STDERR, 'Checkpoint used an unknown container identity.');
exit(82);
CLI);
        chmod($directory . '/docker', 0700);
        AtomicFile::writeJson($directory . '/target.json', new Target($directory . '/docker', 'unix:///captured.baander.app.sock', 'checkpoint-daemon', null, null)->toArray());
        symlink($repository . '/scripts/vendor', $directory . '/vendor');
        file_put_contents($directory . '/hook', '#!' . PHP_BINARY . "\n<?php\n" . <<<'HOOK'
require __DIR__ . '/vendor/autoload.php';
$arguments = array_slice($argv, 1);
$receipt = ['arguments' => $arguments, 'targetFile' => getenv('BAANDER_TOOLING_TARGET_FILE')];
file_put_contents(getenv('CHECKPOINT_CALLBACK'), json_encode($receipt));
$checkpoint = new \Baander\Tooling\Fixtures\Worker\Checkpoint($arguments, getenv('CHECKPOINT_REPOSITORY'), 10);
$receipt['web'] = $checkpoint->web;
file_put_contents(getenv('CHECKPOINT_CALLBACK'), json_encode($receipt));
$client = null;
try {
    $checkpoint->inspectBefore();
    $client = $checkpoint->runtime->start([PHP_BINARY, '-r', 'sleep(10);']);
    $checkpoint->mediaReady($client, 2);
} finally {
    if ($client !== null) { $checkpoint->runtime->stop($client); }
    $checkpoint->runtime->close();
}
HOOK);
        chmod($directory . '/hook', 0700);
        $environment = array_replace(getenv(), [
            'BAANDER_TOOLING_TARGET_FILE' => $directory . '/target.json',
            'BAANDER_DOCKER_EXECUTABLE' => '/missing/ambient-docker',
            'BAANDER_TEST_ARTIFACTS' => $state->artifacts,
            'CHECKPOINT_WEB' => $state->webName,
            'CHECKPOINT_CALLS' => $directory . '/calls',
            'CHECKPOINT_CALLBACK' => $directory . '/callback.json',
            'CHECKPOINT_REPOSITORY' => $repository,
        ]);
        $commands = new OperatorCommands($repository, $environment, 30);
        try {
            $commands->exportTarget($directory . '/forwarded-target.json');
            $reflection = new \ReflectionClass(OperatorQualification::class);
            $qualification = $reflection->newInstanceWithoutConstructor();
            $reflection->getProperty('state')->setValue($qualification, $state);
            $reflection->getProperty('commands')->setValue($qualification, $commands);
            $scenario = new OperatorScenario($qualification);
            $failure = null;
            try {
                new \ReflectionMethod($scenario, 'runCapacityCheckpoint')->invoke($scenario, $directory . '/hook');
            } catch (\Throwable $error) {
                $failure = $error;
            }
            $callback = AtomicFile::readJson($directory . '/callback.json');
            self::assertSame([$state->artifacts, $state->controlName, $state->workerName, $state->webName,
                $state->runId . '-postgres', 'worker_operator_test'], $callback['arguments']);
            self::assertSame($state->webName, $callback['web']);
            self::assertSame($directory . '/forwarded-target.json', $callback['targetFile']);
            if ($failure !== null) {
                throw $failure;
            }
            $calls = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($directory . '/calls', FILE_IGNORE_NEW_LINES));
            self::assertContains(['--host', 'unix:///captured.baander.app.sock', 'inspect', $state->controlName,
                $state->workerName, $state->runId . '-postgres', $state->runId . '-redis', $state->webName], $calls);
            self::assertContains(['--host', 'unix:///captured.baander.app.sock', 'exec', $state->webName,
                'test', '-f', 'storage/two-command/capacity-ready'], $calls);
        } finally {
            $commands->close();
            Collector::remove($directory);
        }
    }
}
