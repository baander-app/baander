<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Console;

use App\Auth\Application\Command\LoginBlock\DeleteAllLoginBlocksCommand;
use App\Auth\Application\Command\LoginBlock\DeleteLoginBlockCommand;
use App\Auth\Application\CommandHandler\LoginBlock\DeleteAllLoginBlocksHandler;
use App\Auth\Application\CommandHandler\LoginBlock\DeleteLoginBlockHandler;
use App\Auth\Application\Query\LoginBlock\ListLoginBlocksQuery;
use App\Auth\Application\QueryHandler\LoginBlock\ListLoginBlocksHandler;
use App\Auth\Domain\Model\LoginBlock;
use App\Auth\Domain\Repository\LoginBlockRepositoryInterface;
use App\Auth\Interface\Console\LoginBlockDeleteCommand;
use App\Auth\Interface\Console\LoginBlockListCommand;
use App\Auth\Interface\Resource\LoginBlockResource;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Console\AdminCommandSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

final class LoginBlockCommandsTest extends TestCase
{
    public function testListPrintsThePageTheRepositoryReturnsAndJsonIsTheApiData(): void
    {
        $newest = LoginBlock::create('192.0.2.10', 'bot@baander.app', 'filled', 'curl/8');
        $older = LoginBlock::create('192.0.2.11', 'other@baander.app', 'spam', 'wget/1');
        $blocks = $this->createMock(LoginBlockRepositoryInterface::class);
        $blocks->expects(self::exactly(2))->method('findRecent')->with(2, 1)->willReturn([$newest, $older]);
        $blocks->method('countRecent')->willReturn(3);
        $command = new LoginBlockListCommand($this->support($blocks));

        $json = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $json->execute(['--limit' => '2', '--offset' => '1', '--json' => true]));
        self::assertSame(
            LoginBlockResource::collection([$newest, $older]),
            json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );

        $table = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $table->execute(['--limit' => '2', '--offset' => '1']));
        self::assertMatchesRegularExpression('/192\.0\.2\.10.*\n.*192\.0\.2\.11/', $table->getDisplay());
        self::assertStringContainsString('Blocks 2-3 of 3.', $table->getDisplay());
    }

    public function testListWithNoBlocksSaysSo(): void
    {
        $blocks = $this->createStub(LoginBlockRepositoryInterface::class);
        $blocks->method('findRecent')->willReturn([]);
        $blocks->method('countRecent')->willReturn(0);

        $tester = new CommandTester(new LoginBlockListCommand($this->support($blocks)));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No login blocks are recorded.', $tester->getDisplay());
    }

    public function testListRejectsAPageOutOfRange(): void
    {
        $blocks = $this->createMock(LoginBlockRepositoryInterface::class);
        $blocks->expects(self::never())->method('findRecent');
        $command = new LoginBlockListCommand($this->support($blocks));

        self::assertSame(Command::INVALID, (new CommandTester($command))->execute(['--limit' => '101']));
        self::assertSame(Command::INVALID, (new CommandTester($command))->execute(['--offset' => '-1']));
        self::assertSame(Command::INVALID, (new CommandTester($command))->execute(['--limit' => 'abc']));
    }

    public function testDeleteRemovesOneBlockAndAnUnknownOrMalformedIdFails(): void
    {
        $known = new Uuid();
        $blocks = $this->createMock(LoginBlockRepositoryInterface::class);
        $blocks->expects(self::exactly(2))
            ->method('deleteByUuid')
            ->willReturnCallback(static fn (Uuid $id): bool => $id->equals($known));
        $blocks->expects(self::never())->method('deleteAll');
        $command = new LoginBlockDeleteCommand($this->support($blocks));

        self::assertSame(Command::SUCCESS, (new CommandTester($command))->execute(['id' => $known->toString()], ['interactive' => false]));

        $unknown = new CommandTester($command);
        $unknownId = (new Uuid())->toString();
        self::assertSame(Command::FAILURE, $unknown->execute(['id' => $unknownId], ['interactive' => false]));
        self::assertStringContainsString(sprintf('Login block "%s" not found.', $unknownId), $unknown->getDisplay());

        $malformed = new CommandTester($command);
        self::assertSame(Command::FAILURE, $malformed->execute(['id' => 'not-a-uuid'], ['interactive' => false]));
        self::assertStringContainsString('Login block "not-a-uuid" not found.', $malformed->getDisplay());
    }

    public function testDeleteAllNeedsForceWithoutATerminal(): void
    {
        $blocks = $this->createMock(LoginBlockRepositoryInterface::class);
        $blocks->expects(self::never())->method('deleteAll');

        $tester = new CommandTester(new LoginBlockDeleteCommand($this->support($blocks)));

        self::assertSame(Command::INVALID, $tester->execute(['--all' => true], ['interactive' => false]));
    }

    public function testDeleteAllAsksOnATerminalAndRemovesEveryBlockWhenConfirmed(): void
    {
        $blocks = $this->createMock(LoginBlockRepositoryInterface::class);
        $blocks->expects(self::once())->method('deleteAll');
        $command = new LoginBlockDeleteCommand($this->support($blocks));

        $declined = new CommandTester($command);
        $declined->setInputs(['no']);
        self::assertSame(Command::FAILURE, $declined->execute(['--all' => true]));

        $confirmed = new CommandTester($command);
        $confirmed->setInputs(['yes']);
        self::assertSame(Command::SUCCESS, $confirmed->execute(['--all' => true]));
    }

    public function testDeleteAllWithForceRemovesEveryBlock(): void
    {
        $blocks = $this->createMock(LoginBlockRepositoryInterface::class);
        $blocks->expects(self::once())->method('deleteAll');

        $tester = new CommandTester(new LoginBlockDeleteCommand($this->support($blocks)));

        self::assertSame(Command::SUCCESS, $tester->execute(['--all' => true, '--force' => true], ['interactive' => false]));
    }

    public function testDeleteNeedsExactlyOneOfAnIdAndAll(): void
    {
        $blocks = $this->createMock(LoginBlockRepositoryInterface::class);
        $blocks->expects(self::never())->method('deleteByUuid');
        $blocks->expects(self::never())->method('deleteAll');
        $command = new LoginBlockDeleteCommand($this->support($blocks));

        self::assertSame(Command::INVALID, (new CommandTester($command))->execute([], ['interactive' => false]));
        self::assertSame(Command::INVALID, (new CommandTester($command))->execute(
            ['id' => (new Uuid())->toString(), '--all' => true, '--force' => true],
            ['interactive' => false],
        ));
    }

    private function support(LoginBlockRepositoryInterface $blocks): AdminCommandSupport
    {
        return new AdminCommandSupport(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            ListLoginBlocksQuery::class => [new ListLoginBlocksHandler($blocks)],
            DeleteLoginBlockCommand::class => [new DeleteLoginBlockHandler($blocks)],
            DeleteAllLoginBlocksCommand::class => [new DeleteAllLoginBlocksHandler($blocks)],
        ]))]));
    }
}
