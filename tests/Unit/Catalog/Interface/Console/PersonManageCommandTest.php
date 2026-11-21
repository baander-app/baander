<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Console;

use App\Catalog\Application\Command\Person\ManagePersonCommand;
use App\Catalog\Interface\Console\PersonManageCommand;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Console\AdminCommandSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class PersonManageCommandTest extends TestCase
{
    public function testProfileFieldsReachTheApplicationAndJsonUsesStdout(): void
    {
        $id = (new PublicId())->toString();
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn(object $command): bool => $command instanceof ManagePersonCommand
                && $command->action === 'create' && $command->profile === ['name' => 'Alex', 'gender' => null]))
            ->willReturn(new Envelope(new \stdClass(), [new HandledStamp(['publicId' => $id], 'person-handler')]));
        $tester = new CommandTester(new PersonManageCommand(new AdminCommandSupport($bus)));
        self::assertSame(0, $tester->execute(['action' => 'create', '--fields' => '{"name":"Alex","gender":null}', '--json' => true], ['capture_stderr_separately' => true]));
        self::assertSame(['publicId' => $id], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testInvalidJsonDoesNotDispatch(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $tester = new CommandTester(new PersonManageCommand(new AdminCommandSupport($bus)));
        self::assertSame(2, $tester->execute(['action' => 'edit', '--fields' => '[1]'], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertNotSame('', $tester->getErrorOutput());
    }

    public function testMergeNeedsForceInNonInteractiveMode(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $tester = new CommandTester(new PersonManageCommand(new AdminCommandSupport($bus)));
        self::assertSame(2, $tester->execute(['action' => 'merge'], ['interactive' => false, 'capture_stderr_separately' => true]));
        self::assertStringContainsString('--force', $tester->getErrorOutput());
    }

    public function testForceUnlinkDispatchesAndUnwrapsValidationFailure(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $command): never {
            self::assertInstanceOf(ManagePersonCommand::class, $command);
            self::assertSame('unlink-artist', $command->action);
            throw new HandlerFailedException(new Envelope($command), [new InvalidInputException('Invalid Artist link.')]);
        });
        $tester = new CommandTester(new PersonManageCommand(new AdminCommandSupport($bus)));
        self::assertSame(2, $tester->execute(['action' => 'unlink-artist', '--force' => true], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Invalid Artist link.', $tester->getErrorOutput());
    }
}
