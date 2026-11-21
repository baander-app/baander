<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\OpenApi;

use App\Shared\Application\Port\OpenApiExporterInterface;
use InvalidArgumentException;
use OpenApi\Assembler;
use ReflectionClass;
use ReflectionMethod;
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
        $assembler->collect(new ReflectionClass(NativeOpenApiDefinition::class));
        $classes = [];
        $routes = [];
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
            $classes[$class] = new ReflectionClass($class);
            $routes[$class . '::' . $method][] = [$name, $route];
        }
        foreach ($classes as $class) {
            $assembler->collect($class);
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
                $methods = $route->getMethods() ?: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD', 'TRACE'];
                foreach ($methods as $method) {
                    if ($operation->method !== null && $operation->method !== strtolower($method)) {
                        continue;
                    }
                    $copy = $operation->copy();
                    $copy->method = strtolower($method);
                    $this->routeMetadata->apply($copy, $name, $route, $reflector);
                    $specification->add($copy);
                }
            }
        }
        $document = $this->compiler->compile($assembler);

        return match ($format) {
            'json' => json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'yaml' => Yaml::dump($document, 20, 2),
        };
    }
}
