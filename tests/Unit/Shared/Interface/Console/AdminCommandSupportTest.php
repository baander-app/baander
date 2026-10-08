<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Console;

use App\Auth\Application\Query\OAuth\ListRegisteredClientsQuery;
use App\Auth\Application\Service\AdminUserSettings;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Interface\Console\OAuthClientListCommand;
use App\Auth\Interface\Console\OAuthClientMessageDispatcher;
use App\Auth\Interface\Resource\AdminOAuthClientResource;
use App\Shared\Application\Actor;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Interface\Console\AdminCommandSupport;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Throwable;

final class AdminCommandSupportTest extends TestCase
{
    private int $dispatched = 0;

    public function testAConflictRaisedByTheHandlerFailsWithItsMessageOnStderr(): void
    {
        $tester = new CommandTester($this->actionCommand(new ConflictException('A scan of Music is already running.')));

        $exitCode = $tester->execute([], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('A scan of Music is already running.', $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
    }

    public function testInvalidInputIsAnInvalidExit(): void
    {
        $tester = new CommandTester($this->actionCommand(new InvalidInputException('The cron expression is not valid.')));

        $exitCode = $tester->execute([], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('The cron expression is not valid.', $tester->getErrorOutput());
    }

    public function testInvalidInputPrintsTheMessagesPerFieldAfterTheMessage(): void
    {
        $tester = new CommandTester($this->actionCommand(new InvalidInputException(
            'Validation failed.',
            ['name' => ['This value should not be blank.'], 'expression' => ['Not a cron expression.', 'Too long.']],
        )));

        $exitCode = $tester->execute([], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::INVALID, $exitCode);
        $errors = $tester->getErrorOutput();
        $this->assertStringContainsString('Validation failed.', $errors);
        $this->assertStringContainsString('name: This value should not be blank.', $errors);
        $this->assertStringContainsString('expression: Not a cron expression.', $errors);
        $this->assertStringContainsString('expression: Too long.', $errors);
    }

    public function testIntegerOptionIsNullWhenAbsentAndRejectsANonInteger(): void
    {
        $definition = new InputDefinition([new InputOption('limit', null, InputOption::VALUE_REQUIRED)]);

        $this->assertNull(AdminCommandSupport::integerOption(new ArrayInput([], $definition), 'limit'));
        $this->assertSame(25, AdminCommandSupport::integerOption(new ArrayInput(['--limit' => '25'], $definition), 'limit'));

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('--limit must be an integer.');
        AdminCommandSupport::integerOption(new ArrayInput(['--limit' => 'abc'], $definition), 'limit');
    }

    public function testUuidRejectsAMalformedValueNamingIt(): void
    {
        $this->assertSame(
            '0192f3c4-0000-7000-8000-000000000001',
            AdminCommandSupport::uuid('0192f3c4-0000-7000-8000-000000000001', 'The job ID')->toString(),
        );

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('The job ID must be a UUID.');
        AdminCommandSupport::uuid('jazz', 'The job ID');
    }

    public function testAnUnknownTargetFailsNamingTheTarget(): void
    {
        $tester = new CommandTester($this->actionCommand(new NotFoundException('No library has the ID "music".')));

        $exitCode = $tester->execute([], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('No library has the ID "music".', $tester->getErrorOutput());
    }

    public function testAnOutcomeFromANestedDispatchIsUnwrappedThroughEveryHandlerFailure(): void
    {
        $inner = $this->support(new InvalidInputException('The cron expression is not valid.'));
        $outer = new AdminCommandSupport(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            stdClass::class => [static fn (): mixed => $inner->dispatch(new stdClass())],
        ]))]));
        $tester = new CommandTester($this->command($outer, destructive: false));

        $this->assertSame(Command::INVALID, $tester->execute([]));
    }

    public function testJsonPrintsExactlyTheDataArrayOfTheResourceCollection(): void
    {
        $clients = $this->clients();
        $tester = new CommandTester($this->listCommand($clients));

        $exitCode = $tester->execute(['--json' => true], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode, $tester->getErrorOutput());
        $this->assertSame(
            AdminOAuthClientResource::collection($clients),
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testJsonOfAnEmptyListIsAnEmptyArray(): void
    {
        $tester = new CommandTester($this->listCommand([]));

        $tester->execute(['--json' => true], ['capture_stderr_separately' => true]);

        $this->assertSame([], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testTheTablePrintsOneRowPerItem(): void
    {
        $clients = $this->clients();
        $tester = new CommandTester($this->listCommand($clients));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        foreach ($clients as $client) {
            $this->assertSame(1, substr_count($display, $client->getPublicId()->toString()), $display);
        }
        $this->assertSame(1, substr_count($display, 'Living room TV'));
        $this->assertSame(1, substr_count($display, 'Kitchen speaker'));
    }

    public function testADestructiveCommandWithoutATerminalOrForceDoesNotDispatch(): void
    {
        $tester = new CommandTester($this->command($this->support(null), destructive: true));

        $exitCode = $tester->execute([], ['interactive' => false, 'capture_stderr_separately' => true]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertSame(0, $this->dispatched);
        $this->assertStringContainsString('--force', $tester->getErrorOutput());
    }

    public function testADestructiveCommandWithForceDispatchesOnce(): void
    {
        $tester = new CommandTester($this->command($this->support(null), destructive: true));

        $exitCode = $tester->execute(['--force' => true], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        $this->assertSame(1, $this->dispatched);
    }

    public function testADestructiveCommandOnATerminalAsksFirst(): void
    {
        $confirmed = new CommandTester($this->command($this->support(null), destructive: true));
        $confirmed->setInputs(['yes']);
        $this->assertSame(Command::SUCCESS, $confirmed->execute([]));
        $this->assertSame(1, $this->dispatched);

        $declined = new CommandTester($this->command($this->support(null), destructive: true));
        $declined->setInputs(['no']);
        $this->assertSame(Command::FAILURE, $declined->execute([]));
        $this->assertSame(1, $this->dispatched, 'A declined change is not dispatched.');
    }

    public function testTheCliActorIsTheValueAdminUserSettingsLogs(): void
    {
        $this->assertSame('cli', Actor::CLI);
        $this->assertSame(Actor::CLI, AdminUserSettings::CLI_ACTOR);
    }

    /** A bus whose handler counts dispatches and throws $failure when one is given. */
    private function support(?Throwable $failure): AdminCommandSupport
    {
        return new AdminCommandSupport(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            stdClass::class => [function () use ($failure): string {
                ++$this->dispatched;
                if ($failure !== null) {
                    throw $failure;
                }

                return 'done';
            }],
        ]))]));
    }

    private function actionCommand(Throwable $failure): Command
    {
        return $this->command($this->support($failure), destructive: false);
    }

    /** An admin command built on the support: it dispatches one message, asking first when destructive. */
    private function command(AdminCommandSupport $support, bool $destructive): Command
    {
        return new class ($support, $destructive) extends Command {
            public function __construct(private readonly AdminCommandSupport $support, private readonly bool $destructive)
            {
                parent::__construct('app:test:action');
            }

            protected function configure(): void
            {
                AdminCommandSupport::addForceOption($this);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $io = new SymfonyStyle($input, $output);
                if ($this->destructive) {
                    $refused = AdminCommandSupport::confirm($input, $io, 'Delete the library?');
                    if ($refused !== null) {
                        return $refused;
                    }
                }

                try {
                    $this->support->dispatch(new stdClass());
                } catch (Throwable $exception) {
                    return AdminCommandSupport::fail($io, $exception);
                }

                $io->success('Done.');

                return Command::SUCCESS;
            }
        };
    }

    /** @param list<Client> $clients */
    private function listCommand(array $clients): OAuthClientListCommand
    {
        return new OAuthClientListCommand(new OAuthClientMessageDispatcher(new AdminCommandSupport(
            new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
                ListRegisteredClientsQuery::class => [static fn (): array => $clients],
            ]))]),
        )));
    }

    /** @return list<Client> */
    private function clients(): array
    {
        return [
            Client::registerDevice('Living room TV'),
            Client::registerPublic('Kitchen speaker', ['https://speaker.baander.app/callback']),
        ];
    }
}
