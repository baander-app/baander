<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Console;

use App\Auth\Application\Command\User\ChangeEmailCommand;
use App\Auth\Application\Exception\EmailAddressInUseException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Interface\Console\ChangeUserEmailCommand;
use App\Auth\Domain\Model\User;
use App\Auth\Interface\Resource\AdminUserResource;
use App\Shared\Domain\Model\Email;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Messenger\Stamp\HandledStamp;
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
    private User $changed;

    protected function setUp(): void
    {
        $this->changed = User::createByOperator(new Email('alice.new@baander.app'), 'hashed', 'Alice', ['ROLE_USER']);
    }

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

    public function testPrintsTheChangedUserAsTheApiReturnsItWithJson(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(['identifier' => 'alice@baander.app', 'email' => 'Alice.New@baander.app', '--json' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(AdminUserResource::from($this->changed), json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
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

        self::assertSame(Command::INVALID, $tester->getStatusCode());
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

            return new Envelope($message, [new HandledStamp($this->changed, 'handler')]);
        });

        return new ChangeUserEmailCommand(new AdminCommandSupport($bus));
    }
}
