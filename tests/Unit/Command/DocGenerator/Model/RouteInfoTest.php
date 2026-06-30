<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\DocGenerator\Model;

use App\Command\DocGenerator\Model\RouteInfo;
use PHPUnit\Framework\TestCase;

final class RouteInfoTest extends TestCase
{
    public function testConstructorDefaultsDescriptionToEmptyString(): void
    {
        $route = new RouteInfo(
            methods: 'GET',
            path: '/api/users',
            name: 'api_users_list',
            controllerFqcn: 'App\Auth\Interface\Http\UserController',
            methodName: 'list',
        );

        $this->assertSame('GET', $route->methods);
        $this->assertSame('/api/users', $route->path);
        $this->assertSame('api_users_list', $route->name);
        $this->assertSame('App\Auth\Interface\Http\UserController', $route->controllerFqcn);
        $this->assertSame('list', $route->methodName);
        $this->assertSame('', $route->description);
    }

    public function testConstructorAssignsProvidedDescription(): void
    {
        $route = new RouteInfo(
            methods: 'POST',
            path: '/api/users',
            name: 'api_users_create',
            controllerFqcn: 'App\Auth\Interface\Http\UserController',
            methodName: 'create',
            description: 'Create a new user account.',
        );

        $this->assertSame('POST', $route->methods);
        $this->assertSame('Create a new user account.', $route->description);
    }

    public function testMultipleHttpMethodsAreStoredVerbatim(): void
    {
        $route = new RouteInfo(
            methods: 'GET|POST',
            path: '/api/songs',
            name: 'api_songs',
            controllerFqcn: 'App\Catalog\Interface\Http\SongController',
            methodName: 'handle',
        );

        $this->assertSame('GET|POST', $route->methods);
    }
}
