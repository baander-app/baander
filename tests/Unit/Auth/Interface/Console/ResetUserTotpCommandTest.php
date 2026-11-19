<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Console;

use App\Auth\Application\Command\User\ResetUserTotpCommand as ResetUserTotpMessage;
use App\Auth\Domain\Model\User;
use App\Auth\Interface\Console\ResetUserTotpCommand;
use App\Auth\Interface\Resource\AdminUserResource;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Domain\Model\Email;
use App\Shared\Interface\Console\AdminCommandSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class ResetUserTotpCommandTest extends TestCase
{
    private User $user;
    /** @var list<ResetUserTotpMessage> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->user = User::register(new Email('alice@baander.app'), 'hash', 'Alice');
    }

    public function testDispatchesTheSharedResetAndPrintsTheApiDataWithJson(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(['identifier' => 'alice@baander.app', '--json' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('alice@baander.app', $this->dispatched[0]->identifier);
        self::assertSame(AdminUserResource::from($this->user), json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testSaysTheSessionsEnded(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(['identifier' => 'alice@baander.app']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('signed out', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    public function testAUserWithoutTotpFails(): void
    {
        $conflict = new ConflictException('TOTP is not on for "alice@baander.app"; there is nothing to reset.');
        $tester = new CommandTester($this->command($conflict));

        $tester->execute(['identifier' => 'alice@baander.app']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('TOTP is not on', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    private function command(?\Throwable $failure = null): ResetUserTotpCommand
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) use ($failure): Envelope {
            self::assertInstanceOf(ResetUserTotpMessage::class, $message);
            if ($failure !== null) {
                throw new HandlerFailedException(new Envelope($message), [$failure]);
            }
            $this->dispatched[] = $message;

            return new Envelope($message, [new HandledStamp($this->user, 'handler')]);
        });

        return new ResetUserTotpCommand(new AdminCommandSupport($bus));
    }
}
