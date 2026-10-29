<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Process\{EngineExecutor, ExecutionContext};
use Baander\Tooling\Resources\ComposeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComposeProjectTest extends TestCase
{
    private string $directory;
    private EngineExecutor $executor;
    private Client $docker;
    /** @var array<string,string> */
    private array $environment;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-compose-owner-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->environment = array_replace(getenv(), ['BACKEND_FAKE_ROOT' => $this->directory]);
        $this->executor = new EngineExecutor(ExecutionContext::bounded(30, $this->environment));
        $this->docker = new Client($this->executor, new Target(__DIR__ . '/Fixtures/backend-docker.php', 'unix:///baander.app/frozen.sock', 'fixture-daemon', null, null), $this->environment);
    }

    protected function tearDown(): void
    {
        $this->executor->close();
        Collector::remove($this->directory);
    }

    private function owner(): ComposeProject
    {
        return new ComposeProject($this->directory . '/ownership.json', $this->docker, $this->environment);
    }

    public function testIntentionAndPrivateFrozenLayoutPrecedeStartupAndRecoveryUsesSavedTarget(): void
    {
        $owner = $this->owner();
        $owner->prepare('baander-fixture', '/unused/compose.yaml', $this->directory, [], '/srv/baander');
        $receipt = json_decode(file_get_contents($owner->receipt), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $receipt['resources']);
        self::assertSame('unix:///baander.app/frozen.sock', $receipt['target']['endpoint']);
        $frozen = json_decode(file_get_contents($receipt['file']), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('/srv/baander', $frozen['services']['app']['working_dir']);
        self::assertSame($receipt['token'], $frozen['services']['app']['labels']['org.baander.tooling.run']);
        self::assertSame($receipt['token'], $frozen['volumes']['postgres_data']['labels']['org.baander.tooling.run']);
        self::assertSame(0600, fileperms($receipt['file']) & 0777);
        $owner->run(['up', '--detach', 'app']);
        $saved = json_decode(file_get_contents($owner->receipt), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotEmpty($saved['resources']);
        $this->executor->close();
        $report = ComposeProject::recover($owner->receipt, array_replace($this->environment, ['DOCKER_HOST' => 'tcp://changed.baander.app:2376']), 10);
        self::assertTrue($report->successful(), $report->message());
        self::assertFileDoesNotExist($owner->receipt);
        self::assertFileDoesNotExist($receipt['file']);
    }

    public function testBrokenReceiptSymlinkIsNotSuccessfulAbsence(): void
    {
        $owner = $this->owner();
        symlink($this->directory . '/missing', $owner->receipt);
        $report = $owner->cleanup(10);
        self::assertFalse($report->successful());
        self::assertTrue(is_link($owner->receipt));
        self::assertStringContainsString('symlink', $report->message());
    }

    /** @return iterable<string,array{string,string}> */
    public static function foreignResources(): iterable
    {
        yield 'generated volume name' => ['volume', 'baander-fixture_postgres_data'];
        yield 'generated network name' => ['network', 'baander-fixture_private'];
        yield 'explicit volume name' => ['volume', 'baander.app-foreign-data'];
        yield 'explicit network name' => ['network', 'baander.app-foreign-network'];
    }

    #[DataProvider('foreignResources')]
    public function testConfiguredForeignResourceIsRejectedBeforeUpAndDown(string $kind, string $name): void
    {
        file_put_contents($this->directory . '/state.json', json_encode([
            'containers' => [], 'projects' => [],
            'configured_names' => [$kind => $name],
            'foreign_resources' => [$kind => [$name => ['Name' => $name, 'Id' => str_repeat('c', 64), 'Labels' => []]]],
        ], JSON_THROW_ON_ERROR));
        $owner = $this->owner();
        $owner->prepare('baander-fixture', '/unused/compose.yaml', $this->directory, []);
        try {
            $owner->run(['up', '--detach', 'app']);
            self::fail('Started Compose with a foreign configured resource');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('configured resource ownership', $error->getMessage());
        }
        $report = $owner->cleanup(10);
        self::assertFalse($report->successful());
        self::assertFileExists($owner->receipt);
        $commands = file($this->directory . '/commands.jsonl');
        self::assertFalse(array_any($commands, static fn(string $line): bool => array_intersect(['up', 'down'], json_decode($line, true)) !== []));
        $state = json_decode(file_get_contents($this->directory . '/state.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey($name, $state['foreign_resources'][$kind]);
    }

    /** @return iterable<string,array{bool}> */
    public static function changedOwnership(): iterable
    {
        yield 'label changed' => [false];
        yield 'immutable identity changed' => [true];
    }

    #[DataProvider('changedOwnership')]
    public function testChangedOwnershipRefusesDownAndRetainsRecoveryReceipt(bool $identityChanged): void
    {
        $owner = $this->owner();
        $owner->prepare('baander-fixture', '/unused/compose.yaml', $this->directory, []);
        $owner->run(['up', '--detach', 'app']);
        $path = $this->directory . '/state.json';
        $state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if ($identityChanged) {
            $state['project_ids']['baander-fixture'] = str_repeat('b', 64);
        } else {
            $state['projects']['baander-fixture']['org.baander.tooling.run'] = 'another-owner';
        }
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
        $report = $owner->cleanup(10);
        self::assertFalse($report->successful());
        self::assertStringContainsString($identityChanged ? 'identity changed' : 'ownership does not match', $report->message());
        self::assertFileExists($owner->receipt);
        $commands = file($this->directory . '/commands.jsonl');
        self::assertFalse(array_any($commands, static fn(string $line): bool => in_array('down', json_decode($line, true), true)));
    }
}
