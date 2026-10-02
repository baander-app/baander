<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\Dev;

use App\Command\Dev\SetupCommand;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class SetupCommandTest extends TestCase
{
    private KernelInterface&Stub $kernel;
    private Filesystem&Stub $filesystem;

    protected function setUp(): void
    {
        $this->kernel = $this->createStub(KernelInterface::class);
        $this->filesystem = $this->createStub(Filesystem::class);
    }

    public function testConfigureSetsNameAndDescription(): void
    {
        $command = $this->createCommand();

        $this->assertSame('app:dev:setup', $command->getName());
        $this->assertSame(
            'Bootstrap development environment: run migrations, generate OAuth keys, seed OAuth clients, create dev users.',
            $command->getDescription(),
        );
    }

    public function testConfigureExposesFreshAndSkipKeysOptions(): void
    {
        $definition = $this->createCommand()->getDefinition();

        $this->assertTrue($definition->hasOption('fresh'));
        $this->assertTrue($definition->hasOption('skip-keys'));

        $fresh = $definition->getOption('fresh');
        $skipKeys = $definition->getOption('skip-keys');

        // Both are value-less boolean flags.
        $this->assertFalse($fresh->acceptValue());
        $this->assertFalse($skipKeys->acceptValue());
        $this->assertFalse($fresh->getDefault());
        $this->assertFalse($skipKeys->getDefault());

        // Short-cut aliases.
        $this->assertSame('f', $fresh->getShortcut());
        $this->assertSame('k', $skipKeys->getShortcut());
    }

    /**
     * execute() shells out to doctrine and clears the cache directory, so it is
     * an integration concern and intentionally not exercised here.
     */
    private function createCommand(): SetupCommand
    {
        return new SetupCommand(
            $this->kernel,
            $this->filesystem,
            sys_get_temp_dir() . '/baander-project',
        );
    }
}
