<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\Dev;

use App\Command\Dev\SetupCommand;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class SetupCommandTest extends TestCase
{
    private KernelInterface&MockObject $kernel;
    private Filesystem&MockObject $filesystem;
    private MessageBusInterface&MockObject $bus;

    protected function setUp(): void
    {
        $this->kernel = $this->createMock(KernelInterface::class);
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
    }

    public function testConfigureSetsNameAndDescription(): void
    {
        $command = $this->createCommand();

        $this->assertSame('app:dev:setup', $command->getName());
        $this->assertSame(
            'Bootstrap development environment: run migrations, generate OAuth keys, create dev users.',
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
            $this->bus,
            sys_get_temp_dir() . '/baander-project',
        );
    }
}
