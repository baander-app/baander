<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Console;

use App\Auth\Application\Command\User\CreateUserCommand;
use App\Auth\Interface\Console\CreateUsersCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class CreateUsersCommandTest extends TestCase
{
    public function testNonInteractiveRunDispatchesTheStandardDevelopmentUsers(): void
    {
        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(2))->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            },
        );
        $command = new CreateUsersCommand($bus);

        $exitCode = (new CommandTester($command))->execute([], ['interactive' => false]);

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
}
