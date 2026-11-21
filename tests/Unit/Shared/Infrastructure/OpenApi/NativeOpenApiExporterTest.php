<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\OpenApi;

use App\Shared\Infrastructure\OpenApi\NativeOpenApiExporter;
use OpenApi\Spec as OA;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Routing\Route as RoutingRoute;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class NativeOpenApiExporterTest extends TestCase
{
    public function testNativeRouteExportPreservesNamesParametersModelsAndAnonymousSecurity(): void
    {
        $routes = new RouteCollection();
        $routes->add('director_show', new RoutingRoute(
            '/api/directors/{publicId}',
            defaults: ['_controller' => NativeDirectorFixture::class . '::show'],
            requirements: ['publicId' => '[a-z0-9]{21}'],
            methods: ['GET'],
        ));
        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($routes);
        $document = json_decode(new NativeOpenApiExporter($router)->render(), true, flags: JSON_THROW_ON_ERROR);

        $operation = $document['paths']['/api/directors/{publicId}']['get'];
        self::assertSame('get_director_show', $operation['operationId']);
        self::assertSame([], $operation['security']);
        self::assertSame(['Directors'], $operation['tags']);
        self::assertSame(['limit', 'publicId'], array_column($operation['parameters'], 'name'));
        self::assertSame(50, $operation['parameters'][0]['schema']['default']);
        self::assertSame([
            'name' => 'publicId',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string', 'pattern' => '[a-z0-9]{21}'],
        ], $operation['parameters'][1]);
        self::assertSame(
            ['$ref' => '#/components/schemas/NativeDirector'],
            $operation['responses'][200]['content']['application/json']['schema'],
        );
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'profileUrl' => ['type' => 'string', 'nullable' => true],
            ],
            'required' => ['name', 'profileUrl'],
        ], $document['components']['schemas']['NativeDirector']);
        self::assertSame([['bearerAuth' => []]], $document['security']);
    }

    public function testPayloadReferencesPreserveNestedArraysExplicitRequiredDefaultsAndEnumValues(): void
    {
        $routes = new RouteCollection();
        $routes->add('playback_control', new RoutingRoute(
            '/api/playback/{publicId}',
            ['_controller' => NativePlaybackFixture::class . '::control'],
            ['publicId' => '[a-z0-9]{21}'],
            methods: ['POST'],
        ));
        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($routes);
        $exporter = new NativeOpenApiExporter($router);
        $document = json_decode($exporter->render(), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/playback/{publicId}']['post'];

        self::assertSame('custom_playback_control', $operation['operationId']);
        self::assertSame('^[a-z]+$', $operation['parameters'][0]['schema']['pattern']);
        self::assertTrue($operation['parameters'][0]['required']);
        self::assertSame([
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/NativeControl']]],
            'required' => true,
        ], $operation['requestBody']);
        $schema = $document['components']['schemas']['NativeControl'];
        self::assertSame(['action', 'payload', 'version'], $schema['required']);
        self::assertSame(
            self::normalized(['type' => 'array', 'items' => ['type' => 'string'], 'nullable' => true, 'default' => null]),
            self::normalized($schema['properties']['queue']),
        );
        self::assertSame(
            self::normalized([
                'type' => 'number',
                'format' => 'float',
                'nullable' => true,
                'default' => null,
                'maximum' => 1,
                'minimum' => 0,
            ]),
            self::normalized($schema['properties']['volume']),
        );
        self::assertFalse($schema['properties']['payload']['additionalProperties']);
        self::assertArrayNotHasKey('default', $schema['properties']['payload']);
        self::assertArrayNotHasKey('implementationDetail', $schema['properties']);
        self::assertSame(['type' => 'string', 'enum' => ['play', 'pause']], $document['components']['schemas']['NativeActionFixture']);
        self::assertSame($document, Yaml::parse($exporter->render('yaml')));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRealNativeExporterDoesNotLoadDeprecatedOpenApiPipeline(): void
    {
        $routes = new RouteCollection();
        $routes->add('director_show', new RoutingRoute(
            '/api/directors/{publicId}',
            ['_controller' => NativeDirectorFixture::class . '::show'],
            methods: ['GET'],
        ));
        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($routes);
        $document = new NativeOpenApiExporter($router)->render();
        self::assertStringContainsString('NativeDirector', $document);
        $deprecated = array_filter(
            get_declared_classes(),
            static function (string $class): bool {
                return str_starts_with($class, 'OpenApi\\Annotations\\')
                    || str_starts_with($class, 'OpenApi\\Attributes\\')
                    || in_array($class, [
                        'OpenApi\\Context',
                        'OpenApi\\Serializer',
                        'OpenApi\\Generator',
                        'OpenApi\\Analysis',
                        'OpenApi\\Builder\\Mode',
                    ], true);
            },
        );
        self::assertSame([], array_values($deprecated));
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function normalized(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::normalized($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}

#[OA\Tag(name: 'Directors', description: 'Movie directors')]
final class NativeDirectorFixture
{
    #[Route('/api/directors/{publicId}', methods: ['GET'])]
    #[OA\Operation\Get(
        parameters: [
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                schema: new OA\Schema(type: 'integer', default: 50),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Director',
                content: new OA\MediaType\Json(ref: NativeDirectorResourceFixture::class),
            ),
        ],
        security: [],
    )]
    public function show(): void {}
}

#[OA\Schema(
    type: 'object',
    properties: [
        'name' => new OA\Schema(type: 'string'),
        'profileUrl' => new OA\Schema(type: 'string', nullable: true),
    ],
    required: ['name', 'profileUrl'],
    component: 'NativeDirector',
)]
final class NativeDirectorResourceFixture {}


final class NativePlaybackFixture
{
    #[OA\Operation\Post(
        operationId: 'custom_playback_control',
        parameters: [
            new OA\Parameter(
                name: 'publicId',
                in: 'path',
                schema: new OA\Schema(type: 'string', pattern: '^[a-z]+$'),
            ),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Accepted'),
        ],
    )]
    public function control(#[MapRequestPayload] NativeControlFixture $payload): void {}
}

#[OA\Schema(
    type: 'object',
    properties: [
        'action' => new OA\Schema(ref: NativeActionFixture::class),
        'queue' => new OA\Schema(
            type: 'array',
            nullable: true,
            items: new OA\Schema(type: 'string'),
            default: null,
        ),
        'volume' => new OA\Schema(
            type: 'number',
            format: 'float',
            nullable: true,
            minimum: 0,
            maximum: 1,
            default: null,
        ),
        'payload' => new OA\Schema(
            type: 'object',
            properties: [
                'mode' => new OA\Schema(
                    type: 'string',
                    enum: ['compact', 'expanded'],
                ),
            ],
            required: ['mode'],
            additionalProperties: false,
        ),
        'version' => new OA\Schema(type: 'integer', minimum: 0),
    ],
    required: ['action', 'payload', 'version'],
    component: 'NativeControl',
)]
final readonly class NativeControlFixture
{
    public function __construct(
        public array $payload = [],
        public int $version = 0,
        public string $implementationDetail = 'private',
    ) {}
}

#[OA\Schema(type: 'string')]
enum NativeActionFixture: string
{
    case Play = 'play';
    case Pause = 'pause';
}
