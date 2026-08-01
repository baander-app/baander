<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\DocGenerator\Model;

use App\Command\DocGenerator\Model\HandlerInfo;
use PHPUnit\Framework\TestCase;

final class HandlerInfoTest extends TestCase
{
    public function testConstructorDefaultsLayerToApplication(): void
    {
        $info = new HandlerInfo(
            handlerFqcn: 'App\Auth\Application\CommandHandler\CreateUserHandler',
            handlerShortName: 'CreateUserHandler',
            commandFqcn: 'App\Auth\Application\Command\CreateUserCommand',
            commandShortName: 'CreateUserCommand',
        );

        $this->assertSame('App\Auth\Application\CommandHandler\CreateUserHandler', $info->handlerFqcn);
        $this->assertSame('CreateUserHandler', $info->handlerShortName);
        $this->assertSame('App\Auth\Application\Command\CreateUserCommand', $info->commandFqcn);
        $this->assertSame('CreateUserCommand', $info->commandShortName);
        $this->assertSame('Application', $info->layer);
    }

    public function testConstructorAcceptsCustomLayer(): void
    {
        $info = new HandlerInfo(
            handlerFqcn: 'App\Catalog\Infrastructure\CommandHandler\ImportCatalogHandler',
            handlerShortName: 'ImportCatalogHandler',
            commandFqcn: 'App\Catalog\Application\Command\ImportCatalogCommand',
            commandShortName: 'ImportCatalogCommand',
            layer: 'Infrastructure',
        );

        $this->assertSame('Infrastructure', $info->layer);
    }
}
