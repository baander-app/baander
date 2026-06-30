<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\OAuth;

use App\Command\OAuth\GenerateKeysCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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

    public function testExecuteAbortsGracefullyWhenUserDeclinesOverwrite(): void
    {
        // Pre-seed an existing key so the overwrite prompt is triggered.
        file_put_contents($this->privateKeyPath, 'existing');
        $originalContents = (string) file_get_contents($this->privateKeyPath);

        $tester = new CommandTester($this->createCommand());
        // Decline the overwrite confirmation.
        $exitCode = $tester->execute([], ['interactive' => false]);

        // Without interactive input, the confirm defaults to false (decline).
        $this->assertSame(Command::SUCCESS, $exitCode);
        // The existing key is left untouched.
        $this->assertSame($originalContents, file_get_contents($this->privateKeyPath));
        $this->assertStringContainsString('aborted', $tester->getDisplay());
    }

    private function createCommand(): GenerateKeysCommand
    {
        return new GenerateKeysCommand($this->privateKeyPath, $this->publicKeyPath);
    }
}
