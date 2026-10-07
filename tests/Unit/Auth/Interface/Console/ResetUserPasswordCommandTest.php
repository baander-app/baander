<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Console;

use App\Auth\Application\Command\User\SetUserPasswordCommand;
use App\Auth\Application\Exception\PasswordPolicyException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Interface\Console\ResetUserPasswordCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class ResetUserPasswordCommandTest extends TestCase
{
    /** @var list<SetUserPasswordCommand> */
    private array $dispatched = [];

    public function testDispatchesTheSharedOperatorResetWithThePasswordFromStdin(): void
    {
        $tester = new CommandTester($this->command(dispatches: true, stdin: "operator-password-1\n"));

        $tester->execute(['identifier' => 'alice@baander.app', '--password' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(1, $this->dispatched);
        self::assertSame('alice@baander.app', $this->dispatched[0]->identifier);
        self::assertSame('operator-password-1', $this->dispatched[0]->password);
        self::assertStringContainsString('signed out', $tester->getDisplay());
    }

    public function testPromptsForThePasswordInteractively(): void
    {
        $tester = new CommandTester($this->command(dispatches: true));
        $tester->setInputs(['prompted-password-2']);

        $tester->execute(['identifier' => '0192a3b4-c5d6-7890-abcd-ef1234567890']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('prompted-password-2', $this->dispatched[0]->password);
    }

    public function testFailsWithoutAPassword(): void
    {
        $tester = new CommandTester($this->command(dispatches: true, stdin: ''));

        $tester->execute(['identifier' => 'alice@baander.app', '--password' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], $this->dispatched);
    }

    public function testReportsTheUseCaseRejection(): void
    {
        foreach ([UserNotFoundException::forIdentifier('nobody@baander.app'), PasswordPolicyException::length(8, 255)] as $failure) {
            $tester = new CommandTester($this->command(dispatches: false, stdin: "operator-password-1\n", failure: $failure));

            $tester->execute(['identifier' => 'nobody@baander.app', '--password' => true], ['interactive' => false]);

            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString($failure->getMessage(), preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        }
    }

    private function command(bool $dispatches, ?string $stdin = null, ?\Throwable $failure = null): ResetUserPasswordCommand
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) use ($dispatches, $failure): Envelope {
            self::assertInstanceOf(SetUserPasswordCommand::class, $message);
            if (!$dispatches) {
                throw new HandlerFailedException(new Envelope($message), [$failure ?? new \RuntimeException('failed')]);
            }
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        if ($stdin === null) {
            return new ResetUserPasswordCommand($bus);
        }
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $stdin);
        rewind($stream);

        return new ResetUserPasswordCommand($bus, $stream);
    }
}
