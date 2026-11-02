<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Docker\Target;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Fixtures\Worker\{OperatorCommands, OperatorProvisioning, OperatorQualification, OperatorResources, OperatorRun};
use PHPUnit\Framework\TestCase;

final class WorkerOperatorWebOriginTest extends TestCase
{
    public function testWebLaunchUsesTheDisposableOriginForEveryUrlSetting(): void
    {
        $directory = sys_get_temp_dir() . '/baander-web-origin-' . bin2hex(random_bytes(8));
        mkdir($directory . '/artifacts', 0700, true);
        AtomicFile::writeJson($directory . '/state.json', ['daemonId' => 'image-daemon', 'calls' => [], 'containers' => [], 'images' => []]);
        AtomicFile::writeJson($directory . '/target.json', new Target(__DIR__ . '/Fixtures/image-ledger-docker.php', 'unix:///baander.app/docker.sock', 'image-daemon', null, null)->toArray());
        $environment = array_replace(getenv(), ['BAANDER_TOOLING_TARGET_FILE' => $directory . '/target.json', 'IMAGE_LEDGER_STATE' => $directory . '/state.json']);
        $commands = new OperatorCommands(dirname(__DIR__, 3), $environment, 30);
        $resources = new OperatorResources($commands, $directory . '/artifacts');
        $state = new OperatorRun();
        $state->runId = 'baander-web-origin';
        $state->appHome = ApplicationLayout::load()->root;
        $state->work = $directory;
        $state->artifacts = $directory . '/artifacts';
        $state->imageId = 'sha256:' . str_repeat('a', 64);
        $state->secretsVolume = 'baander-web-origin-secrets';
        $qualification = new \ReflectionClass(OperatorQualification::class)->newInstanceWithoutConstructor();
        foreach (['root' => dirname(__DIR__, 3), 'state' => $state, 'commands' => $commands, 'resources' => $resources] as $name => $value) {
            new \ReflectionProperty(OperatorQualification::class, $name)->setValue($qualification, $value);
        }
        try {
            new OperatorProvisioning($qualification)->startWeb();
            $calls = AtomicFile::readJson($directory . '/state.json')['calls'];
            $create = array_find($calls, static fn(array $call): bool => ($call[0] ?? '') === 'create');
            self::assertNotNull($create);
            foreach (['APP_URL', 'DEFAULT_URI'] as $name) {
                self::assertContains($name . '=http://127.0.0.1:9501', $create);
            }
            self::assertContains('APP_DOMAIN=127.0.0.1', $create);
            self::assertSame('http://127.0.0.1:19251', $state->webBase);
        } finally {
            $resources->cleanup();
            $commands->close();
            Collector::remove($directory);
        }
    }
}
