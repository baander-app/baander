<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Process\CompletionPolicy;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use Baander\Tooling\Process\ProcessSpec;
use PHPUnit\Framework\TestCase;

final class StaticCheckStagingStatusTest extends TestCase
{
    public function testFailedStagingRemovalPreservesNonzeroCheckStatus(): void
    {
        $directory = sys_get_temp_dir() . '/baander-staging-status-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $environment = array_replace(getenv(), [
            'TMPDIR' => $directory,
            'PATH' => $directory . '/bin:' . getenv('PATH'),
            'BAANDER_DOCKER_EXECUTABLE' => __DIR__ . '/Fixtures/staging-status-docker.php',
            'DOCKER_HOST' => 'unix:///baander.app/docker.sock',
            'STAGING_STATUS_STATE' => $directory,
        ]);
        unset($environment['DOCKER_CONTEXT'], $environment['BAANDER_TEST_DOCKER_CONTEXT'], $environment['DOCKER_TLS'], $environment['DOCKER_TLS_VERIFY']);
        $executor = new EngineExecutor(ExecutionContext::bounded(30, $environment));
        try {
            foreach (['source/vendor/composer', 'source/scripts/vendor', 'source/packages/baander-tooling/resources', 'bin'] as $path) {
                mkdir($directory . '/' . $path, 0700, true);
            }
            file_put_contents($directory . '/source/vendor/autoload.php', '<?php');
            file_put_contents($directory . '/source/scripts/vendor/autoload.php', '<?php');
            file_put_contents($directory . '/source/composer.lock', '{"packages":[],"packages-dev":[]}');
            file_put_contents($directory . '/source/vendor/composer/installed.json', '{"packages":[]}');
            file_put_contents($directory . '/source/packages/baander-tooling/resources/application-layout.json', json_encode(ApplicationLayout::load()->toArray()));
            file_put_contents($directory . '/bin/rm', "#!/bin/sh\necho 'Controlled staging removal failure' >&2\nexit 23\n");
            chmod($directory . '/bin/rm', 0755);
            $executor->run(new ProcessSpec(['git', 'init', '-b', 'fixture'], cwd: $directory . '/source'));
            $loader = dirname(__DIR__, 3) . '/vendor/autoload.php';
            file_put_contents($directory . '/invoke.php', '<?php require ' . var_export($loader, true) . ';' . <<<'CHILD'
                try {
                    exit(\Baander\Tooling\Backend\StaticCheck::main(['--image', 'baander.app/app:fixture', '--', 'php', 'fixture.php'], getenv('STAGING_STATUS_STATE') . '/source'));
                } catch (\RuntimeException $error) {
                    fwrite(STDERR, $error->getMessage());
                    exit($error->getCode() ?: 1);
                }
                CHILD);
            $result = $executor->run(new ProcessSpec([PHP_BINARY, $directory . '/invoke.php']), new CompletionPolicy(null));
            self::assertSame(67, $result->exitCode, $result->stderr);
            self::assertStringContainsString('Static-check staging cleanup failed', $result->stderr);
            self::assertStringContainsString('rm exited with status 23', $result->stderr);
            self::assertFileDoesNotExist($directory . '/container.json');
            $workspaces = glob($directory . '/baander-check-*');
            self::assertCount(1, $workspaces);
            self::assertFileDoesNotExist($workspaces[0] . '/resources.json');
        } finally {
            $executor->close();
            Collector::remove($directory);
        }
    }
}
