<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\OpenApi;

use App\Shared\Application\Port\OpenApiExporterInterface;
use InvalidArgumentException;
use OpenApi\Assembler;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

final readonly class NativeOpenApiExporter implements OpenApiExporterInterface
{
    public function __construct(
        private RouterInterface $router,
        private NativeSpecificationCompiler $compiler = new NativeSpecificationCompiler(),
        private NativeRouteMetadata $routeMetadata = new NativeRouteMetadata(),
    ) {}

    public function render(string $format = 'json'): string
    {
        if (!in_array($format, ['json', 'yaml'], true)) {
            throw new InvalidArgumentException('Format must be "json" or "yaml".');
        }
        $assembler = new Assembler();
        /** @var ReflectionClass<object> $definition */
        $definition = new ReflectionClass(NativeOpenApiDefinition::class);
        $assembler->collect($definition);
        $classes = [];
        $routes = [];
        $methods = [];
        foreach ($this->router->getRouteCollection() as $name => $route) {
            if ($name === 'app.swagger' || !preg_match('~^/api(?!/doc$)~', $route->getPath())) {
                continue;
            }
            $controller = $route->getDefault('_controller');
            if (!is_string($controller)) {
                continue;
            }
            [$class, $method] = str_contains($controller, '::')
                ? explode('::', $controller, 2)
                : [$controller, '__invoke'];
            if (!class_exists($class) || !method_exists($class, $method)) {
                continue;
            }
            $classes[$class] ??= new ReflectionClass($class);
            $key = $class . '::' . $method;
            $routes[$key][] = [$name, $route];
            $methods[$key] ??= new ReflectionMethod($class, $method);
        }
        foreach ($classes as $class) {
            $assembler->collect($class);
        }
        $requests = [];
        foreach ($methods as $method) {
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getAttributes(MapRequestPayload::class) === []
                    && $parameter->getAttributes(MapQueryString::class) === []
                ) {
                    continue;
                }
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $requests[$type->getName()] ??= new ReflectionClass($type->getName());
                }
            }
        }
        foreach ($requests as $request) {
            $assembler->collect($request);
        }
        $specification = $assembler->getSpecification();
        $operations = $specification->operations;
        $specification->operations = [];
        foreach ($operations as $operation) {
            $reflector = $operation->getReflector();
            if (!$reflector instanceof ReflectionMethod) {
                continue;
            }
            $key = $reflector->getDeclaringClass()->getName() . '::' . $reflector->getName();
            foreach ($routes[$key] ?? [] as [$name, $route]) {
                $routeMethods = $route->getMethods() ?: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD', 'TRACE'];
                foreach ($routeMethods as $routeMethod) {
                    $methodName = strtolower($routeMethod);
                    if ($operation->method !== null && $operation->method !== $methodName) {
                        continue;
                    }
                    $copy = $operation->copy();
                    $copy->method = $methodName;
                    $this->routeMetadata->apply($copy, $name, $route, $reflector);
                    $specification->add($copy);
                }
            }
        }
        $document = $this->compiler->compile($assembler);

        return match ($format) {
            'json' => json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'yaml' => Yaml::dump(
                $document,
                10,
                2,
                Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE,
            ),
        };
    }
}
