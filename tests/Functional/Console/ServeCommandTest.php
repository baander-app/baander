<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\Command\ServerRunCommand;
use SwooleBundle\SwooleBundle\Server\Config\Socket;
use SwooleBundle\SwooleBundle\Server\Config\Sockets;
use SwooleBundle\SwooleBundle\Server\DefaultHttpServerConfiguration;

final class ServeCommandTest extends KernelTestCase
{
    public function testCanonicalNameAndLegacyAliasShareTheRealServerCommand(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $serve = $application->find('app:serve');
        $legacy = $application->find('swoole:server:run');

        self::assertSame($serve, $legacy);
        self::assertInstanceOf(ServerRunCommand::class, $serve);
        self::assertSame('app:serve', $serve->getName());
        self::assertContains('swoole:server:run', $serve->getAliases());
        self::assertTrue($serve->getDefinition()->hasOption('port'));
        self::assertTrue($serve->getDefinition()->hasOption('host'));
        self::assertTrue($serve->getDefinition()->hasOption('api'));
    }

    public function testConfiguredLoopbackApiHostIsKept(): void
    {
        $sockets = new Sockets(new Socket('0.0.0.0', 9501), new Socket('127.0.0.1', 9200));

        $this->prepareServer($sockets, ['--api-port' => '9201']);

        self::assertSame('127.0.0.1', $sockets->getApiSocket()->host());
        self::assertSame(9201, $sockets->getApiSocket()->port());
    }

    public function testApiOptionWithoutConfiguredApiSocketBindsTheConfiguredLoopbackHost(): void
    {
        $sockets = new Sockets(new Socket('0.0.0.0', 9501));

        $this->prepareServer($sockets, ['--api' => true]);

        self::assertSame('127.0.0.1', self::getContainer()->getParameter('swoole.http_server.api.host'));
        self::assertSame('127.0.0.1', $sockets->getApiSocket()->host());
        self::assertSame(9200, $sockets->getApiSocket()->port());
    }

    /**
     * Applies the command's socket options to a private configuration, so the
     * test neither binds ports nor changes the container's shared sockets.
     *
     * @param array<string, mixed> $options
     */
    private function prepareServer(Sockets $sockets, array $options): void
    {
        $command = new Application(self::bootKernel())->find('app:serve');
        self::assertInstanceOf(ServerRunCommand::class, $command);

        new ReflectionMethod($command, 'prepareServerConfiguration')->invoke(
            $command,
            new DefaultHttpServerConfiguration(new Swoole(), $sockets),
            new ArrayInput($options, $command->getDefinition()),
        );
    }
}
