<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Console;

use App\Auth\Application\Command\User\MarkEmailVerifiedCommand as MarkEmailVerifiedMessage;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Domain\Model\User;
use App\Auth\Interface\Console\MarkEmailVerifiedCommand;
use App\Auth\Interface\Resource\AdminUserResource;
use App\Shared\Domain\Model\Email;
use App\Shared\Interface\Console\AdminCommandSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class MarkEmailVerifiedCommandTest extends TestCase
{
    private User $user;
    /** @var list<MarkEmailVerifiedMessage> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->user = User::createByOperator(new Email('alice@baander.app'), 'hash', 'Alice', ['ROLE_USER']);
    }

    public function testDispatchesTheSharedUseCaseAndPrintsTheApiDataWithJson(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(['identifier' => 'alice@baander.app', '--json' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('alice@baander.app', $this->dispatched[0]->identifier);
        self::assertSame(AdminUserResource::from($this->user), json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testSaysTheAddressIsVerified(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(['identifier' => 'alice@baander.app']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('alice@baander.app, is verified', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    public function testAnUnknownUserFails(): void
    {
        $tester = new CommandTester($this->command(new UserNotFoundException('User not found.')));

        $tester->execute(['identifier' => 'nobody@baander.app']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('User not found', $tester->getDisplay());
    }

    private function command(?\Throwable $failure = null): MarkEmailVerifiedCommand
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) use ($failure): Envelope {
            self::assertInstanceOf(MarkEmailVerifiedMessage::class, $message);
            if ($failure !== null) {
                throw new HandlerFailedException(new Envelope($message), [$failure]);
            }
            $this->dispatched[] = $message;

            return new Envelope($message, [new HandledStamp($this->user, 'handler')]);
        });

        return new MarkEmailVerifiedCommand(new AdminCommandSupport($bus));
    }
}
