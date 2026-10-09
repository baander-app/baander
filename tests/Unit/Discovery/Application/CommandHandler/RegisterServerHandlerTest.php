<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Application\CommandHandler;

use App\Discovery\Application\Command\RegisterServerCommand;
use App\Discovery\Application\CommandHandler\RegisterServerHandler;
use App\Discovery\Application\Port\ServerInstancePortInterface;
use App\Discovery\Domain\Event\ServerRegistered;
use App\Discovery\Domain\Model\ServerInstance;
use App\Shared\Application\Exception\InvalidInputException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class RegisterServerHandlerTest extends TestCase
{
    private ServerInstancePortInterface&MockObject $serverPort;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private RegisterServerHandler $handler;

    protected function setUp(): void
    {
        $this->serverPort = $this->createMock(ServerInstancePortInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnCallback(fn (object $e) => $e);
        $this->handler = new RegisterServerHandler($this->serverPort, $this->eventDispatcher);
    }

    public function testRegistersServerAndDispatchesEvent(): void
    {
        $server = ServerInstance::create(
            serverUrl: 'https://music.baander.app',
            name: 'Home Server',
            version: '1.2.3',
            apiKey: 'secret-key',
        );

        $this->serverPort->expects($this->once())
            ->method('register')
            ->with('https://music.baander.app', 'Home Server', '1.2.3', $this->matchesRegularExpression('/^[0-9a-f]{64}$/'))
            ->willReturn($server);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn (object $e) => $e instanceof ServerRegistered
                && $e->getServerUrl() === 'https://music.baander.app'
                && $e->getName() === 'Home Server'));

        $result = ($this->handler)(new RegisterServerCommand(
            'https://music.baander.app',
            'Home Server',
            '1.2.3',
        ));

        $this->assertSame($server, $result);
    }

    /** A Docker service name may contain an underscore; the API's Url constraint accepts it too. */
    public function testRegistersAServerWhoseHostNameHasAnUnderscore(): void
    {
        $server = ServerInstance::create(
            serverUrl: 'http://baander_web:8080',
            name: 'Compose Server',
            version: '1.2.3',
            apiKey: 'secret-key',
        );

        $this->serverPort->expects($this->once())
            ->method('register')
            ->with('http://baander_web:8080', 'Compose Server', '1.2.3', $this->anything())
            ->willReturn($server);
        $this->eventDispatcher->expects($this->once())->method('dispatch');

        $this->assertSame($server, ($this->handler)(new RegisterServerCommand('http://baander_web:8080', 'Compose Server', '1.2.3')));
    }

    public function testRejectsAUrlWithoutAHostWithoutRegistering(): void
    {
        $this->serverPort->expects($this->never())->method('register');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(InvalidInputException::class);

        ($this->handler)(new RegisterServerCommand('https://', 'Home Server', '1.2.3'));
    }

    public function testRejectsAMalformedUrlWithoutRegistering(): void
    {
        $this->serverPort->expects($this->never())->method('register');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(InvalidInputException::class);

        ($this->handler)(new RegisterServerCommand('ftp://music.baander.app', 'Home Server', '1.2.3'));
    }

    public function testRejectsABlankNameWithoutRegistering(): void
    {
        $this->serverPort->expects($this->never())->method('register');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(InvalidInputException::class);

        ($this->handler)(new RegisterServerCommand('https://music.baander.app', '  ', '1.2.3'));
    }

    public function testRejectsABlankVersionWithoutRegistering(): void
    {
        $this->serverPort->expects($this->never())->method('register');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(InvalidInputException::class);

        ($this->handler)(new RegisterServerCommand('https://music.baander.app', 'Home Server', ''));
    }
}
