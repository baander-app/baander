<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Console;

use App\Auth\Application\Command\User\CreateUserCommand;
use App\Auth\Interface\Console\CreateUsersCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class CreateUsersCommandTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function admittedRuntimes(): iterable
    {
        yield 'dev kernel' => ['dev', false];
        yield 'prod kernel on a development instance' => ['prod', true];
    }

    #[DataProvider('admittedRuntimes')]
    public function testNonInteractiveRunDispatchesTheStandardDevelopmentUsers(string $environment, bool $developmentInstance): void
    {
        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(2))->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            },
        );
        $command = new CreateUsersCommand($bus, $environment, $developmentInstance);

        $exitCode = new CommandTester($command)->execute([], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('app:dev:create-users', $command->getName());
        self::assertSame(
            [
                ['admin@baander.test', 'Admin User', 'admin', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']],
                ['user@baander.test', 'Test User', 'user', ['ROLE_USER']],
            ],
            array_map(static function (object $message): array {
                self::assertInstanceOf(CreateUserCommand::class, $message);

                return [
                    $message->getEmail()->toString(),
                    $message->getName(),
                    $message->getPlainPassword(),
                    $message->getRoles(),
                ];
            }, $dispatched),
        );
    }

    public function testProdKernelWithoutDevelopmentInstanceMarkerCreatesNoUsers(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $tester = new CommandTester(new CreateUsersCommand($bus, 'prod', false));

        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('dev environment', $display);
        self::assertStringContainsString('BAANDER_DEVELOPMENT_INSTANCE=1', $display);
    }
}
