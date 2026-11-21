<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\OpenApi;

use OpenApi\Spec\MediaType\Json;
use OpenApi\Spec\Operation;
use OpenApi\Spec\Parameter;
use OpenApi\Spec\RequestBody;
use OpenApi\Spec\Schema;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Route;

final class NativeRouteMetadata
{
    public function apply(Operation $operation, string $name, Route $route, ReflectionMethod $method): void
    {
        $operation->path = preg_replace('~\.\{_format\}$~', '', $route->getPath());
        $operation->operationId ??= $operation->method . '_' . $name;
        foreach ($route->compile()->getPathVariables() as $variable) {
            if ($variable === '_format') {
                continue;
            }
            $parameter = $this->pathParameter($operation, $variable);
            $parameter->required = true;
            $parameter->schema ??= new Schema(type: 'string');
            $pattern = $route->getRequirement($variable);
            if ($pattern !== null && $parameter->schema->pattern === null) {
                $parameter->schema->pattern = $pattern;
                if (str_contains($pattern, '|') && preg_match('~^[-a-zA-Z0-9_|]+$~', $pattern)) {
                    $parameter->schema->enum ??= explode('|', $pattern);
                }
            }
        }
        if ($operation->requestBody !== null) {
            return;
        }
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getAttributes(MapRequestPayload::class) === []) {
                continue;
            }
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $operation->requestBody = new RequestBody(
                required: !$parameter->isDefaultValueAvailable(),
                content: new Json(ref: $type->getName()),
            );
        }
    }

    private function pathParameter(Operation $operation, string $name): Parameter
    {
        foreach ($operation->parameters ?? [] as $parameter) {
            if ($parameter->name === $name && $parameter->in === 'path') {
                return $parameter;
            }
        }
        $parameter = new Parameter(name: $name, in: 'path');
        $operation->parameters ??= [];
        $operation->parameters[] = $parameter;

        return $parameter;
    }
}
