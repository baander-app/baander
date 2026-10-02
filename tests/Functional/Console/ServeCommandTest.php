<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\Command\ServerRunCommand;

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
}
