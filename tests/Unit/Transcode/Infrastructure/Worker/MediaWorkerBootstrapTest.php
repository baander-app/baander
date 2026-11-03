<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Worker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaWorkerBootstrapTest extends TestCase
{
    public function testHeapExhaustionReportsOnlyFatalLocationThroughPrivateDescriptor(): void
    {
        $directory = sys_get_temp_dir() . '/baander-media-fatal-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        mkdir($directory . '/vendor', 0700);
        copy(dirname(__DIR__, 5) . '/bin/worker-media-job.php', $directory . '/bin/worker-media-job.php');
        file_put_contents($directory . '/vendor/autoload.php', <<<'PHP'
<?php
namespace App\Shared\Application\DTO;
final class DeploymentProfile
{
    public static function fromEnvironment(): never
    {
        ini_set('memory_limit', '16M');
        $privateSecret = str_repeat('private-secret', 3 * 1024 * 1024);
        exit(0);
    }
}
PHP);
        try {
            $process = proc_open([PHP_BINARY, '-n', '-d', 'max_execution_time=5', $directory . '/bin/worker-media-job.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'w']], $pipes, env_vars: []);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            $diagnostic = stream_get_contents($pipes[3]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            self::assertNotSame(0, proc_close($process));
            self::assertSame('', $output);
            self::assertSame('', $error);
            $report = json_decode($diagnostic, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['event', 'errorType', 'sourceFile', 'sourceLine', 'memoryExhausted'], array_keys($report));
            self::assertSame('worker_media_fatal', $report['event']);
            self::assertSame(E_ERROR, $report['errorType']);
            self::assertSame('autoload.php', $report['sourceFile']);
            self::assertGreaterThan(0, $report['sourceLine']);
            self::assertTrue($report['memoryExhausted']);
            self::assertStringNotContainsString('private-secret', $diagnostic);
            self::assertStringNotContainsString('Allowed memory size', $diagnostic);
        } finally {
            unlink($directory . '/vendor/autoload.php');
            unlink($directory . '/bin/worker-media-job.php');
            rmdir($directory . '/vendor');
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }

    /** @param list<string> $arguments */
    #[DataProvider('privateEntrypoints')]
    public function testInvalidInheritedKernelConfigurationFailsWithoutLoadingDotenvOrLeakingCredentials(string $script, array $arguments, bool $diagnosticChannel): void
    {
        $environment = [
            'BAANDER_WORKER_NAMESPACE' => 'test.baander.app',
            'BAANDER_WORKER_BOOT_ID' => str_repeat('a', 32),
            'BAANDER_WORKER_ID' => 'media',
            'BAANDER_WORKER_GENERATION' => '1',
            'BAANDER_WORKER_LEASE_EPOCH' => '1',
            'DATABASE_URL' => 'postgresql://credential:private-secret@baander.app/database',
            'DATABASE_CONTROL_URL' => 'postgresql://credential_control:private-secret@baander.app/database_control',
            // The repository Dotenv files must not fill this missing inherited setting.
            'APP_DEBUG' => '0',
        ];
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if ($diagnosticChannel) {
            $descriptors[3] = ['pipe', 'w'];
        }
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 5) . '/bin/' . $script, ...$arguments], $descriptors, $pipes, env_vars: $environment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        $diagnostic = $diagnosticChannel ? stream_get_contents($pipes[3]) : '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        if ($diagnosticChannel) {
            fclose($pipes[3]);
        }
        self::assertSame(1, proc_close($process));
        self::assertSame('', $output);
        self::assertStringContainsString('deployment reconciliation is required', $error);
        self::assertStringNotContainsString('private-secret', $error);
        self::assertStringNotContainsString('credential', $error);
        if ($script !== 'worker-media.php' && !$diagnosticChannel) {
            self::assertSame("Media execution failed; deployment reconciliation is required.\n", $error);
            return;
        }
        $report = json_decode($script === 'worker-media.php' ? substr($error, strpos($error, '{')) : $diagnostic, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['event', 'exceptionClass', 'sourceFile', 'sourceLine'], array_keys($report));
        self::assertSame('worker_media_failure', $report['event']);
        self::assertSame(\RuntimeException::class, $report['exceptionClass']);
        self::assertSame($script, $report['sourceFile']);
        self::assertGreaterThan(0, $report['sourceLine']);
        self::assertStringNotContainsString('private-secret', $diagnostic);
        self::assertStringNotContainsString('credential', $diagnostic);
    }

    #[DataProvider('launchFenceClients')]
    public function testLaunchFenceAdmitsOnlyTheProfilesSlotClients(string $kind, string $clientId, bool $admitted): void
    {
        $profile = new \App\Shared\Application\DTO\DeploymentProfile('full', 8, 1);
        $environment = [
            'BAANDER_DEPLOYMENT_PROFILE' => 'full',
            'BAANDER_DEPLOYMENT_PROFILE_JSON' => $profile->toJson(),
            'BAANDER_WORKER_NAMESPACE' => 'test.baander.app',
            'BAANDER_WORKER_BOOT_ID' => str_repeat('a', 32),
            'BAANDER_WORKER_ID' => 'media',
            'BAANDER_WORKER_GENERATION' => '1',
            'BAANDER_WORKER_LEASE_EPOCH' => '1',
            'BAANDER_NATIVE_SOCKET' => sys_get_temp_dir() . '/baander-absent-' . bin2hex(random_bytes(8)) . '.sock',
            'BAANDER_NATIVE_CLIENT_ID' => $clientId,
            'BAANDER_NATIVE_CLIENT_GENERATION' => '1',
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
            'DATABASE_URL' => 'postgresql://baander@baander.app/database',
        ];
        $key = $kind === 'video' ? '019a9e53-8192-72dd-a50a-9f15553cecaf' : str_repeat('a', 64);
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 5) . '/bin/worker-media-job.php', $kind, $key],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'], 3 => ['pipe', 'w']], $pipes, env_vars: $environment);
        self::assertIsResource($process);
        $diagnostic = stream_get_contents($pipes[3]);
        fclose($pipes[3]);
        self::assertSame(1, proc_close($process));
        $report = json_decode($diagnostic, true, flags: JSON_THROW_ON_ERROR);
        // An admitted client passes the fence and fails only at the absent native socket.
        self::assertSame($admitted ? \App\Shared\Infrastructure\Worker\NativeExecutionException::class : \RuntimeException::class, $report['exceptionClass']);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function launchFenceClients(): iterable
    {
        yield 'last video slot' => ['video', 'media-video-7', true];
        yield 'video slot beyond the profile' => ['video', 'media-video-8', false];
        yield 'audio slot' => ['audio', 'media-audio-0', true];
        yield 'audio slot beyond the profile' => ['audio', 'media-audio-1', false];
        yield 'audio client for a video job' => ['video', 'media-audio-0', false];
        yield 'shared copy client in a full profile' => ['video', 'media-copy-0', false];
    }

    /** @return iterable<string, array{string, list<string>, bool}> */
    public static function privateEntrypoints(): iterable
    {
        foreach ([true, false] as $diagnosticChannel) {
            $suffix = $diagnosticChannel ? ' with diagnostics' : ' without diagnostics';
            yield 'manager' . $suffix => ['worker-media.php', [], $diagnosticChannel];
            yield 'audio job' . $suffix => ['worker-media-job.php', ['audio', str_repeat('a', 64)], $diagnosticChannel];
            yield 'video job' . $suffix => ['worker-media-job.php', ['video', '019a9e53-8192-72dd-a50a-9f15553cecaf'], $diagnosticChannel];
        }
    }
}
