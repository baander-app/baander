<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Domain\Service;

use App\Scheduler\Domain\Model\SchedulableCommandInterface;
use App\Scheduler\Domain\Model\SchedulableConsoleCommandInterface;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

final class SchedulerRegistryTest extends TestCase
{
    // ---------------------------------------------------------------
    // Empty registry
    // ---------------------------------------------------------------

    public function testEmptyRegistryReportsNothingAllowed(): void
    {
        $registry = new SchedulerRegistry([], []);

        $this->assertFalse($registry->isMessengerCommandAllowed('App\SomeCommand'));
        $this->assertFalse($registry->isConsoleCommandAllowed('app:something'));
        $this->assertSame([], $registry->getMessengerCommands());
        $this->assertSame([], $registry->getConsoleCommands());
    }

    // ---------------------------------------------------------------
    // Messenger commands
    // ---------------------------------------------------------------

    public function testMessengerCommandIsAllowed(): void
    {
        $command = new class implements SchedulableCommandInterface {
            public static function schedulerDescription(): string
            {
                return 'Scans the music library';
            }

            public static function schedulerParameters(): array
            {
                return ['path' => ['type' => 'string', 'required' => true]];
            }
        };

        $registry = new SchedulerRegistry([$command], []);

        $this->assertTrue($registry->isMessengerCommandAllowed($command::class));
    }

    public function testUnknownMessengerCommandIsNotAllowed(): void
    {
        $command = new class implements SchedulableCommandInterface {
            public static function schedulerDescription(): string
            {
                return 'Test';
            }

            public static function schedulerParameters(): array
            {
                return [];
            }
        };

        $registry = new SchedulerRegistry([$command], []);

        $this->assertFalse($registry->isMessengerCommandAllowed('App\Unknown\Command'));
    }

    public function testGetMessengerCommandsReturnsDescriptions(): void
    {
        $command = new class implements SchedulableCommandInterface {
            public static function schedulerDescription(): string
            {
                return 'Syncs country stations';
            }

            public static function schedulerParameters(): array
            {
                return ['country' => ['type' => 'string', 'required' => false, 'default' => 'US']];
            }
        };

        $registry = new SchedulerRegistry([$command], []);
        $commands = $registry->getMessengerCommands();

        $this->assertArrayHasKey($command::class, $commands);
        $this->assertSame('Syncs country stations', $commands[$command::class]['description']);
        $this->assertSame(['country' => ['type' => 'string', 'required' => false, 'default' => 'US']], $commands[$command::class]['parameters']);
    }

    public function testGetMessengerParameterSchemaReturnsParameters(): void
    {
        $command = new class implements SchedulableCommandInterface {
            public static function schedulerDescription(): string
            {
                return 'Test';
            }

            public static function schedulerParameters(): array
            {
                return ['limit' => ['type' => 'int', 'required' => false, 'default' => 100]];
            }
        };

        $registry = new SchedulerRegistry([$command], []);
        $schema = $registry->getMessengerParameterSchema($command::class);

        $this->assertSame(['limit' => ['type' => 'int', 'required' => false, 'default' => 100]], $schema);
    }

    public function testGetMessengerParameterSchemaReturnsEmptyForUnknown(): void
    {
        $registry = new SchedulerRegistry([], []);

        $this->assertSame([], $registry->getMessengerParameterSchema('App\Unknown'));
    }

    // ---------------------------------------------------------------
    // Console commands
    // ---------------------------------------------------------------

    public function testConsoleCommandIsAllowed(): void
    {
        $command = new class extends Command implements SchedulableConsoleCommandInterface {
            public function getName(): ?string
            {
                return 'app:test:run';
            }

            public static function schedulerParameters(): array
            {
                return [];
            }
        };

        $registry = new SchedulerRegistry([], [$command]);

        $this->assertTrue($registry->isConsoleCommandAllowed('app:test:run'));
    }

    public function testUnknownConsoleCommandIsNotAllowed(): void
    {
        $command = new class extends Command implements SchedulableConsoleCommandInterface {
            public function getName(): ?string
            {
                return 'app:known';
            }

            public static function schedulerParameters(): array
            {
                return [];
            }
        };

        $registry = new SchedulerRegistry([], [$command]);

        $this->assertFalse($registry->isConsoleCommandAllowed('app:unknown'));
    }

    public function testConsoleCommandWithNullNameIsIgnored(): void
    {
        $command = new class extends Command implements SchedulableConsoleCommandInterface {
            public function getName(): ?string
            {
                return null;
            }

            public static function schedulerParameters(): array
            {
                return [];
            }
        };

        $registry = new SchedulerRegistry([], [$command]);

        $this->assertSame([], $registry->getConsoleCommands());
    }

    public function testGetConsoleCommandsReturnsDescriptions(): void
    {
        $command = new class extends Command implements SchedulableConsoleCommandInterface {
            public function getName(): ?string
            {
                return 'app:sync';
            }

            public function getDescription(): string
            {
                return 'Syncs data from upstream';
            }

            public static function schedulerParameters(): array
            {
                return ['force' => ['type' => 'bool', 'required' => false]];
            }
        };

        $registry = new SchedulerRegistry([], [$command]);
        $commands = $registry->getConsoleCommands();

        $this->assertArrayHasKey('app:sync', $commands);
        $this->assertSame('Syncs data from upstream', $commands['app:sync']['description']);
        $this->assertSame(['force' => ['type' => 'bool', 'required' => false]], $commands['app:sync']['parameters']);
    }

    public function testGetConsoleParameterSchemaReturnsParameters(): void
    {
        $command = new class extends Command implements SchedulableConsoleCommandInterface {
            public function getName(): ?string
            {
                return 'app:import';
            }

            public static function schedulerParameters(): array
            {
                return ['source' => ['type' => 'string', 'required' => true]];
            }
        };

        $registry = new SchedulerRegistry([], [$command]);
        $schema = $registry->getConsoleParameterSchema('app:import');

        $this->assertSame(['source' => ['type' => 'string', 'required' => true]], $schema);
    }

    public function testGetConsoleParameterSchemaReturnsEmptyForUnknown(): void
    {
        $registry = new SchedulerRegistry([], []);

        $this->assertSame([], $registry->getConsoleParameterSchema('app:unknown'));
    }

    // ---------------------------------------------------------------
    // Multiple commands
    // ---------------------------------------------------------------

    public function testMultipleMessengerCommandsAreRegistered(): void
    {
        $cmd1 = new class implements SchedulableCommandInterface {
            public static function schedulerDescription(): string
            {
                return 'First';
            }

            public static function schedulerParameters(): array
            {
                return [];
            }
        };

        $cmd2 = new class implements SchedulableCommandInterface {
            public static function schedulerDescription(): string
            {
                return 'Second';
            }

            public static function schedulerParameters(): array
            {
                return [];
            }
        };

        $registry = new SchedulerRegistry([$cmd1, $cmd2], []);

        $this->assertTrue($registry->isMessengerCommandAllowed($cmd1::class));
        $this->assertTrue($registry->isMessengerCommandAllowed($cmd2::class));
        $this->assertCount(2, $registry->getMessengerCommands());
    }

    // ---------------------------------------------------------------
    // Lazy initialization
    // ---------------------------------------------------------------

    public function testRegistryInitializesLazily(): void
    {
        $command = new class implements SchedulableCommandInterface {
            public static function schedulerDescription(): string
            {
                return 'Lazy';
            }

            public static function schedulerParameters(): array
            {
                return [];
            }
        };

        $registry = new SchedulerRegistry([$command], []);

        // Before any access, internal arrays are empty
        // After first access, they are populated
        $this->assertTrue($registry->isMessengerCommandAllowed($command::class));

        // Second access should still work (no double-init issue)
        $this->assertTrue($registry->isMessengerCommandAllowed($command::class));
    }
}
