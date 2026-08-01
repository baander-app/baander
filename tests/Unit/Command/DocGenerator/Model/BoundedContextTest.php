<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\DocGenerator\Model;

use App\Command\DocGenerator\Model\BoundedContext;
use App\Command\DocGenerator\Model\ClassInfo;
use App\Command\DocGenerator\Model\HandlerInfo;
use App\Command\DocGenerator\Model\RouteInfo;
use PHPUnit\Framework\TestCase;

final class BoundedContextTest extends TestCase
{
    public function testConstructorWithOnlyRequiredArgumentsDefaultsCollectionsToEmpty(): void
    {
        $context = new BoundedContext(name: 'Auth', description: 'Authentication');

        $this->assertSame('Auth', $context->name);
        $this->assertSame('Authentication', $context->description);
        $this->assertSame([], $context->classes);
        $this->assertSame([], $context->routes);
        $this->assertSame([], $context->aggregateRoots);
        $this->assertSame([], $context->valueObjects);
        $this->assertSame([], $context->handlers);
        $this->assertSame([], $context->layers);
    }

    public function testConstructorAssignsAllProvidedCollections(): void
    {
        $aggregateRoot = new ClassInfo(
            fqcn: 'App\Auth\Domain\Model\User',
            shortName: 'User',
            namespace: 'App\Auth\Domain\Model',
            layer: 'Domain',
            isAggregateRoot: true,
        );
        $valueObject = new ClassInfo(
            fqcn: 'App\Auth\Domain\ValueObject\Email',
            shortName: 'Email',
            namespace: 'App\Auth\Domain\ValueObject',
            layer: 'Domain',
            isValueObject: true,
        );
        $route = new RouteInfo(
            methods: 'POST',
            path: '/api/users',
            name: 'api_users_create',
            controllerFqcn: 'App\Auth\Interface\Http\UserController',
            methodName: 'create',
        );
        $handler = new HandlerInfo(
            handlerFqcn: 'App\Auth\Application\CommandHandler\CreateUserHandler',
            handlerShortName: 'CreateUserHandler',
            commandFqcn: 'App\Auth\Application\Command\CreateUserCommand',
            commandShortName: 'CreateUserCommand',
        );

        $context = new BoundedContext(
            name: 'Catalog',
            description: 'Catalog management',
            classes: [$aggregateRoot, $valueObject],
            routes: [$route],
            aggregateRoots: [$aggregateRoot],
            valueObjects: [$valueObject],
            handlers: [$handler],
            layers: ['Domain', 'Application', 'Interface'],
        );

        $this->assertSame('Catalog', $context->name);
        $this->assertSame('Catalog management', $context->description);
        $this->assertCount(2, $context->classes);
        $this->assertSame($aggregateRoot, $context->classes[0]);
        $this->assertSame($valueObject, $context->classes[1]);
        $this->assertSame([$route], $context->routes);
        $this->assertSame([$aggregateRoot], $context->aggregateRoots);
        $this->assertSame([$valueObject], $context->valueObjects);
        $this->assertSame([$handler], $context->handlers);
        $this->assertSame(['Domain', 'Application', 'Interface'], $context->layers);
    }

    public function testEmptyStringDescriptionIsAllowed(): void
    {
        $context = new BoundedContext(name: 'Shared', description: '');

        $this->assertSame('', $context->description);
    }
}
