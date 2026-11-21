<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\OpenApi;

use App\Catalog\Interface\Controller\MovieController;
use App\Catalog\Interface\Controller\MovieDirectorController;
use App\Shared\Infrastructure\OpenApi\SpecParameterRouteDescriber;
use OpenApi\Annotations as Export;
use OpenApi\Analysis;
use OpenApi\Generator;
use Psr\Log\NullLogger;
use OpenApi\Spec\Parameter;
use OpenApi\Spec\Schema;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Routing\Route;

final class SpecParameterRouteDescriberTest extends TestCase
{
    public function testMovieControllersInstantiateFourteenExactSpecParameters(): void
    {
        $methods = [
            [MovieController::class, 'index', 3],
            [MovieController::class, 'show', 1],
            [MovieController::class, 'update', 1],
            [MovieController::class, 'destroy', 1],
            [MovieDirectorController::class, 'index', 3],
            [MovieDirectorController::class, 'show', 1],
            [MovieDirectorController::class, 'movies', 4],
        ];
        $total = 0;
        foreach ($methods as [$class, $method, $count]) {
            $attributes = new ReflectionMethod($class, $method)->getAttributes(Parameter::class);
            self::assertCount($count, $attributes, $class . '::' . $method);
            foreach ($attributes as $attribute) {
                $parameter = $attribute->newInstance();
                self::assertSame(Parameter::class, $parameter::class);
                self::assertInstanceOf(Schema::class, $parameter->schema);
                ++$total;
            }
        }
        self::assertSame(14, $total);
    }

    public function testParametersReachOnlyRouteMethodsAndMergeWithoutDuplicatesOrReordering(): void
    {
        $cursor = new Export\Parameter(['name' => 'cursor', 'in' => 'query', 'schema' => new Export\Schema(['type' => 'string'])]);
        $page = new Export\Parameter(['name' => 'page', 'in' => 'query', 'schema' => new Export\Schema(['type' => 'integer', 'default' => 99])]);
        $api = new Export\OpenApi(['paths' => [new Export\PathItem([
            'path' => '/modern',
            'get' => new Export\Get([
                'parameters' => [$cursor, $page],
                'responses' => [new Export\Response(['response' => '200', 'description' => 'Existing response'])],
            ]),
        ])]]);
        $describer = new SpecParameterRouteDescriber();
        $route = new Route('/modern', methods: ['GET', 'PATCH']);
        $reflection = new ReflectionMethod(SpecParameterFixture::class, 'modern');
        $describer->describe($api, $route, $reflection);
        $document = json_decode($api->toJson(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['get', 'patch'], array_keys($document['paths']['/modern']));
        $get = $document['paths']['/modern']['get'];
        self::assertSame(['cursor', 'page', 'q'], array_column($get['parameters'], 'name'));
        self::assertSame(1, $get['parameters'][1]['schema']['default']);
        self::assertSame('Existing response', $get['responses'][200]['description']);
        self::assertSame(['page', 'q'], array_column($document['paths']['/modern']['patch']['parameters'], 'name'));
        $describer->describe($api, $route, $reflection);
        self::assertSame($document, json_decode($api->toJson(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testConvertedParametersStayInlineThroughTheNelmioProcessorPipeline(): void
    {
        $api = new Export\OpenApi([
            'info' => new Export\Info(['title' => 'Fixture API', 'version' => '1.0.0']),
            'paths' => [new Export\PathItem([
                'path' => '/modern',
                'get' => new Export\Get([
                    'responses' => [new Export\Response(['response' => '200', 'description' => 'Existing response'])],
                ]),
            ])],
        ]);
        new SpecParameterRouteDescriber()->describe(
            $api,
            new Route('/modern', methods: ['GET']),
            new ReflectionMethod(SpecParameterFixture::class, 'modern'),
        );
        $analysis = new Analysis([], $api->_context);
        $analysis->addAnnotation($api, $api->_context);
        new Generator(new NullLogger())->getProcessorPipeline()->process($analysis);
        self::assertTrue($analysis->validate());
        $document = json_decode($api->toJson(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['page', 'q'], array_column($document['paths']['/modern']['get']['parameters'], 'name'));
        self::assertArrayNotHasKey('parameters', $document['components'] ?? []);
        self::assertArrayNotHasKey('schemas', $document['components'] ?? []);
    }

    public function testMethodsWithoutSpecParametersLeaveTheExistingDocumentUnchanged(): void
    {
        $api = new Export\OpenApi(['paths' => [new Export\PathItem([
            'path' => '/legacy',
            'get' => new Export\Get(['summary' => 'Existing operation']),
        ])]]);
        $before = $api->toJson();
        new SpecParameterRouteDescriber()->describe(
            $api,
            new Route('/legacy', methods: ['GET', 'POST']),
            new ReflectionMethod(SpecParameterFixture::class, 'legacy'),
        );
        self::assertSame($before, $api->toJson());
    }
}

final class SpecParameterFixture
{
    #[Parameter(name: 'page', in: 'query', schema: new Schema(type: 'integer', default: 1))]
    #[Parameter(name: 'q', in: 'query', schema: new Schema(type: 'string'))]
    public function modern(): void {}

    public function legacy(): void {}
}
