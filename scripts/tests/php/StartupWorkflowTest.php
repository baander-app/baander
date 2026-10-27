<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\{ProcessRunner, ProcessSpec};
use PHPUnit\Framework\TestCase;

final class StartupWorkflowTest extends TestCase
{
    public function testUnexpectedCacheRemovalIsRejectedByThePrivateProbe(): void
    {
        $directory = sys_get_temp_dir() . '/baander-startup-refusal-' . bin2hex(random_bytes(8));
        mkdir($directory . '/docker/general', 0700, true);
        mkdir($directory . '/temporary', 0700);
        file_put_contents($directory . '/docker/general/start-web.sh', "#!/bin/sh\nrm -rf /var/www/html/var/cache/prod\n");
        $code = <<<'PHP_CODE'
            require $argv[1];
            try {
                exit(Baander\Tooling\Fixtures\Startup\Probe::main($argv[2]));
            } catch (Throwable $error) {
                fwrite(STDERR, $error->getMessage());
                exit(1);
            }
            PHP_CODE;
        try {
            $result = new ProcessRunner()->run(new ProcessSpec(
                argv: [PHP_BINARY, '-r', $code, dirname(__DIR__, 2) . '/bootstrap.php', $directory],
                environment: [...getenv(), 'TMPDIR' => $directory . '/temporary'],
                timeout: 10,
            ));
            self::assertSame(1, $result->exitCode);
            self::assertStringContainsString('Unexpected startup cleanup command', $result->stderr);
            self::assertSame(['.', '..'], scandir($directory . '/temporary'));
        } finally {
            unlink($directory . '/docker/general/start-web.sh');
            rmdir($directory . '/docker/general');
            rmdir($directory . '/docker');
            rmdir($directory . '/temporary');
            rmdir($directory);
        }
    }

    public function testStartupProbePreservesArgumentsEnvironmentSignalsAndPrivateCleanup(): void
    {
        $directory = sys_get_temp_dir() . '/baander-startup-contract-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $result = new ProcessRunner()->run(new ProcessSpec(
                argv: [PHP_BINARY, dirname(__DIR__, 2) . '/test-container-startup.php'],
                environment: [...getenv(), 'TMPDIR' => $directory, 'APP_ENV' => 'dev'],
                timeout: 15,
            ));
            self::assertSame(0, $result->exitCode, $result->stderr);
            self::assertSame(
                "Web startup preserves arguments, exec PID, signals and exit status; only dev cache is cleared.\n",
                $result->stdout,
            );
            self::assertSame(['.', '..'], scandir($directory));
        } finally {
            rmdir($directory);
        }
    }
}
