<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\OAuth;

use App\Command\OAuth\GenerateKeysCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class GenerateKeysCommandTest extends TestCase
{
    private string $keyDir;
    private string $privateKeyPath;
    private string $publicKeyPath;

    protected function setUp(): void
    {
        $this->keyDir = sys_get_temp_dir() . '/baander_oauth_' . bin2hex(random_bytes(6));
        $this->privateKeyPath = $this->keyDir . '/private.key';
        $this->publicKeyPath = $this->keyDir . '/public.key';
        mkdir($this->keyDir, 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->privateKeyPath);
        @unlink($this->publicKeyPath);
        @rmdir($this->keyDir);
    }

    public function testConfigureSetsNameAndDescription(): void
    {
        $command = $this->createCommand();

        $this->assertSame('app:oauth:generate-keys', $command->getName());
        $this->assertSame(
            'Generate OAuth2 private and public keys for JWT signing.',
            $command->getDescription(),
        );
    }

    public function testConfigureSetsHelpText(): void
    {
        $command = $this->createCommand();

        $this->assertStringContainsString('RSA key pair', $command->getHelp());
    }

    public function testExecuteCreatesPrivateKeyAndPublicKey(): void
    {
        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFileExists($this->privateKeyPath);
        $this->assertFileExists($this->publicKeyPath);

        $privatePem = (string) file_get_contents($this->privateKeyPath);
        $publicPem = (string) file_get_contents($this->publicKeyPath);

        $this->assertStringContainsString('PRIVATE KEY', $privatePem);
        $this->assertStringContainsString('PUBLIC KEY', $publicPem);
        $this->assertStringContainsString('Keys generated', $tester->getDisplay());
        $privateKey = openssl_pkey_get_private($privatePem);
        $this->assertNotFalse($privateKey);
        $details = openssl_pkey_get_details($privateKey);
        $this->assertNotFalse($details);
        $this->assertSame($details['key'], $publicPem);
        $this->assertSame(0600, fileperms($this->privateKeyPath) & 0777);
        $this->assertSame(0600, fileperms($this->publicKeyPath) & 0777);
    }

    public function testExecuteCreatesParentDirectoryWhenMissing(): void
    {
        $nestedDir = $this->keyDir . '/nested/deep';
        $command = new GenerateKeysCommand($nestedDir . '/oauth-private.key', $nestedDir . '/oauth-public.key');

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFileExists($nestedDir . '/oauth-private.key');

        // Cleanup the nested structure.
        @unlink($nestedDir . '/oauth-private.key');
        @unlink($nestedDir . '/oauth-public.key');
        @rmdir($nestedDir);
        @rmdir($this->keyDir . '/nested');
    }

    public function testExecuteRefusesAnExistingPrivateKeyWithoutPrompting(): void
    {
        file_put_contents($this->privateKeyPath, 'existing');
        $tester = new CommandTester($this->createCommand());
        $tester->setInputs(['yes']);
        $exitCode = $tester->execute([], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame('existing', file_get_contents($this->privateKeyPath));
        $this->assertFileDoesNotExist($this->publicKeyPath);
        $this->assertStringContainsString('app:auth:rotate-secrets', $tester->getDisplay());
    }

    public function testExecuteRefusesAnExistingPublicKey(): void
    {
        file_put_contents($this->publicKeyPath, 'existing public');
        $tester = new CommandTester($this->createCommand());

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertSame('existing public', file_get_contents($this->publicKeyPath));
        $this->assertFileDoesNotExist($this->privateKeyPath);
    }

    public function testExecuteRefusesBothExistingKeysAndPreservesTheirBytes(): void
    {
        file_put_contents($this->privateKeyPath, 'existing private');
        file_put_contents($this->publicKeyPath, 'existing public');
        $tester = new CommandTester($this->createCommand());
        $tester->setInputs(['yes']);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertSame('existing private', file_get_contents($this->privateKeyPath));
        $this->assertSame('existing public', file_get_contents($this->publicKeyPath));
    }

    public function testExecuteRefusesDanglingSymlinksAtEitherTarget(): void
    {
        foreach ([$this->privateKeyPath, $this->publicKeyPath] as $path) {
            symlink($this->keyDir . '/missing', $path);
            $tester = new CommandTester($this->createCommand());

            $this->assertSame(Command::FAILURE, $tester->execute([]));
            $this->assertTrue(is_link($path));
            $this->assertFileDoesNotExist($this->keyDir . '/missing');
            $otherPath = $path === $this->privateKeyPath ? $this->publicKeyPath : $this->privateKeyPath;
            $this->assertFileDoesNotExist($otherPath);
            unlink($path);
        }
    }

    public function testExecuteRefusesSymlinkToAnExistingKey(): void
    {
        $target = $this->keyDir . '/existing';
        file_put_contents($target, 'existing private');
        symlink($target, $this->privateKeyPath);
        try {
            $tester = new CommandTester($this->createCommand());
            $this->assertSame(Command::FAILURE, $tester->execute([]));
            $this->assertSame('existing private', file_get_contents($target));
            $this->assertTrue(is_link($this->privateKeyPath));
            $this->assertFileDoesNotExist($this->publicKeyPath);
        } finally {
            unlink($target);
        }
    }

    public function testExecuteRefusesIdenticalTargetsIncludingPathAliases(): void
    {
        foreach ([$this->privateKeyPath, $this->keyDir . '/./private.key'] as $publicPath) {
            $tester = new CommandTester(new GenerateKeysCommand($this->privateKeyPath, $publicPath));
            $this->assertSame(Command::FAILURE, $tester->execute([]));
            $this->assertFileDoesNotExist($this->privateKeyPath);
        }
    }

    public function testExecuteRefusesSymlinkedParentDirectories(): void
    {
        $alias = $this->keyDir . '/alias';
        symlink($this->keyDir, $alias);
        try {
            $tester = new CommandTester(new GenerateKeysCommand($alias . '/private.key', $alias . '/public.key'));
            $this->assertSame(Command::FAILURE, $tester->execute([]));
            $this->assertFileDoesNotExist($this->privateKeyPath);
            $this->assertFileDoesNotExist($this->publicKeyPath);
        } finally {
            unlink($alias);
        }
    }

    public function testSecondCreateFailurePreservesAFileCreatedByAnotherInvocation(): void
    {
        $process = $this->runWithWriteFailure('race');
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->privateKeyPath);
        $this->assertSame('other invocation', file_get_contents($this->publicKeyPath));
    }

    public function testSecondWriteFailureRemovesBothFilesCreatedByThisInvocation(): void
    {
        $process = $this->runWithWriteFailure('write');
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->privateKeyPath);
        $this->assertFileDoesNotExist($this->publicKeyPath);
    }

    public function testInsecureStreamModeRemovesBothFilesCreatedByThisInvocation(): void
    {
        $process = $this->runWithWriteFailure('permissions');
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->privateKeyPath);
        $this->assertFileDoesNotExist($this->publicKeyPath);
    }

    public function testInvalidExportedPemDoesNotCreateEitherOutput(): void
    {
        $process = $this->runWithWriteFailure('pem');
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->privateKeyPath);
        $this->assertFileDoesNotExist($this->publicKeyPath);
    }

    public function testFailureCleanupPreservesReplacementOfAnOwnedFile(): void
    {
        $process = $this->runWithWriteFailure('replacement');
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('replacement bytes', file_get_contents($this->privateKeyPath));
        $this->assertFileDoesNotExist($this->publicKeyPath);
    }

    public function testReplacementAfterSuccessfulWritesCannotReportSuccess(): void
    {
        $process = $this->runWithWriteFailure('replacement-success');
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('replacement bytes', file_get_contents($this->privateKeyPath));
        $this->assertFileDoesNotExist($this->publicKeyPath);
        $this->assertStringContainsString('replaced during initialization', $process->getOutput());
    }

    public function testSymlinkSwapAfterExclusiveOpenDoesNotModifyTheExternalTarget(): void
    {
        $externalPath = $this->keyDir . '/external.key';
        file_put_contents($externalPath, 'external bytes');
        chmod($externalPath, 0644);
        try {
            $process = $this->runWithWriteFailure('symlink-swap');
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame('external bytes', file_get_contents($externalPath));
            clearstatcache(true, $externalPath);
            $this->assertSame(0644, fileperms($externalPath) & 0777);
            $this->assertTrue(is_link($this->publicKeyPath));
            $this->assertFileDoesNotExist($this->privateKeyPath);
        } finally {
            unlink($externalPath);
        }
    }

    private function runWithWriteFailure(string $failure): Process
    {
        // Function overrides live only in the child process; all actual files are local.
        $script = <<<'PHP'
namespace App\Command\OAuth {
    function fopen(string $path, string $mode) {
        if ($path === $GLOBALS['publicPath'] && $GLOBALS['failure'] === 'race') {
            \file_put_contents($path, 'other invocation');
        }
        $stream = \fopen($path, $mode);
        if ($path === $GLOBALS['publicPath'] && $GLOBALS['failure'] === 'symlink-swap') {
            \unlink($path);
            \symlink(\dirname($path) . '/external.key', $path);
        }
        return $stream;
    }
    function fwrite($stream, string $contents) {
        if (\stream_get_meta_data($stream)['uri'] === $GLOBALS['publicPath'] && \in_array($GLOBALS['failure'], ['replacement', 'replacement-success'], true)) {
            \unlink($GLOBALS['privatePath']);
            \file_put_contents($GLOBALS['privatePath'], 'replacement bytes');
            if ($GLOBALS['failure'] === 'replacement') {
                return false;
            }
        }
        if (\stream_get_meta_data($stream)['uri'] === $GLOBALS['publicPath'] && $GLOBALS['failure'] === 'write') {
            \fwrite($stream, \substr($contents, 0, 12));
            return false;
        }
        return \fwrite($stream, $contents);
    }
    function fstat($stream) {
        $stat = \fstat($stream);
        if ($stat !== false && \stream_get_meta_data($stream)['uri'] === $GLOBALS['publicPath'] && $GLOBALS['failure'] === 'permissions') {
            $stat['mode'] = ($stat['mode'] & ~0777) | 0644;
        }
        return $stat;
    }
    function openssl_pkey_export($key, &$output): bool {
        if ($GLOBALS['failure'] === 'pem') {
            $output = 'invalid private PEM';
            return true;
        }
        return \openssl_pkey_export($key, $output);
    }
}
namespace {
    require $argv[1];
    $GLOBALS['privatePath'] = $argv[2];
    $GLOBALS['publicPath'] = $argv[3];
    $GLOBALS['failure'] = $argv[4];
    $tester = new \Symfony\Component\Console\Tester\CommandTester(new \App\Command\OAuth\GenerateKeysCommand($argv[2], $argv[3]));
    $code = $tester->execute([], ['interactive' => false]);
    echo $tester->getDisplay();
    exit($code === \Symfony\Component\Console\Command\Command::FAILURE ? 0 : 1);
}
PHP;
        $process = new Process([
            PHP_BINARY, '-r', $script, dirname(__DIR__, 4) . '/vendor/autoload.php',
            $this->privateKeyPath, $this->publicKeyPath, $failure,
        ]);
        $process->run();

        return $process;
    }

    private function createCommand(): GenerateKeysCommand
    {
        return new GenerateKeysCommand($this->privateKeyPath, $this->publicKeyPath);
    }
}
