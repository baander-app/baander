<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Interface\Console;

use App\Discovery\Application\Command\RegisterServerCommand;
use App\Discovery\Domain\Model\ServerInstance;
use App\Discovery\Interface\Console\DiscoveryRegisterCommand;
use App\Discovery\Interface\Resource\ServerInstanceResource;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Interface\Console\AdminCommandSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class DiscoveryRegisterCommandTest extends TestCase
{
    /** @var list<RegisterServerCommand> */
    private array $dispatched = [];

    public function testRegistersAndPrintsTheServerWithoutAKey(): void
    {
        $server = ServerInstance::create('https://cli.baander.app', 'Home Server', '1.0.0', 'private-key');
        $tester = new CommandTester($this->command($server));

        $tester->execute(['url' => 'https://cli.baander.app', 'name' => 'Home Server', 'version' => '1.0.0', '--json' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('https://cli.baander.app', $this->dispatched[0]->getServerUrl());
        self::assertSame(ServerInstanceResource::from($server), json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private-key', $tester->getDisplay());
    }

    public function testTableOutputNamesThePublicIdAndNotTheKey(): void
    {
        $server = ServerInstance::create('https://cli.baander.app', 'Home Server', '1.0.0', 'private-key');
        $tester = new CommandTester($this->command($server));

        $tester->execute(['url' => 'https://cli.baander.app', 'name' => 'Home Server', 'version' => '1.0.0']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString($server->getPublicId()->toString(), $tester->getDisplay());
        self::assertStringNotContainsString('private-key', $tester->getDisplay());
    }

    public function testAMalformedUrlExitsWithInvalid(): void
    {
        $tester = new CommandTester($this->command(null, new InvalidInputException('The server URL must be a valid http or https URL.')));

        $tester->execute(['url' => 'not a url', 'name' => 'Home Server', 'version' => '1.0.0']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('valid http or https URL', $tester->getDisplay());
    }

    private function command(?ServerInstance $server, ?\Throwable $failure = null): DiscoveryRegisterCommand
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (RegisterServerCommand $message) use ($server, $failure): Envelope {
            $this->dispatched[] = $message;
            $envelope = new Envelope($message);
            if ($failure !== null) {
                throw new HandlerFailedException($envelope, [$failure]);
            }

            return $envelope->with(new HandledStamp($server, 'handler'));
        });

        return new DiscoveryRegisterCommand(new AdminCommandSupport($bus));
    }
}
