<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Console;

use App\Auth\Application\Command\User\ChangeEmailCommand;
use App\Auth\Application\Exception\EmailAddressInUseException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Interface\Console\ChangeUserEmailCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class ChangeUserEmailCommandTest extends TestCase
{
    /** @var list<ChangeEmailCommand> */
    private array $dispatched = [];

    public function testDispatchesTheSharedEmailChange(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(['identifier' => 'alice@baander.app', 'email' => 'Alice.New@baander.app']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(1, $this->dispatched);
        self::assertSame('alice@baander.app', $this->dispatched[0]->identifier);
        self::assertSame('Alice.New@baander.app', $this->dispatched[0]->email);
        self::assertStringContainsString('unverified', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    public function testReportsTheUseCaseRejection(): void
    {
        foreach ([UserNotFoundException::forIdentifier('nobody@baander.app'), EmailAddressInUseException::create()] as $failure) {
            $tester = new CommandTester($this->command($failure));

            $tester->execute(['identifier' => 'nobody@baander.app', 'email' => 'taken@baander.app']);

            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString($failure->getMessage(), preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        }
    }

    public function testReportsAnInvalidAddress(): void
    {
        $tester = new CommandTester($this->command(new \InvalidArgumentException('"not-an-address" is not a valid email address.')));

        $tester->execute(['identifier' => 'alice@baander.app', 'email' => 'not-an-address']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('"not-an-address" is not a valid email address.', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    private function command(?\Throwable $failure = null): ChangeUserEmailCommand
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) use ($failure): Envelope {
            self::assertInstanceOf(ChangeEmailCommand::class, $message);
            if ($failure !== null) {
                throw new HandlerFailedException(new Envelope($message), [$failure]);
            }
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return new ChangeUserEmailCommand($bus);
    }
}
