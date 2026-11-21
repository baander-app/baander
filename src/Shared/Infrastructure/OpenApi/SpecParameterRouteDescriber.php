<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\OpenApi;

use LogicException;
use Nelmio\ApiDocBundle\RouteDescriber\RouteDescriberInterface;
use Nelmio\ApiDocBundle\RouteDescriber\RouteDescriberTrait;
use OpenApi\Annotations\OpenApi;
use OpenApi\Annotations\Operation;
use OpenApi\Annotations\Parameter as ExportParameter;
use OpenApi\Compiler\OpenApi30Compiler;
use OpenApi\Context;
use OpenApi\Serializer;
use OpenApi\Spec\Operation as SpecOperation;
use OpenApi\Spec\Parameter;
use OpenApi\Specification;
use OpenApi\Undefined;
use ReflectionAttribute;
use ReflectionMethod;
use Symfony\Component\Routing\Route;

/** Compiles Spec parameters into the representation required by the current Nelmio exporter. */
final class SpecParameterRouteDescriber implements RouteDescriberInterface
{
    use RouteDescriberTrait;

    public function describe(OpenApi $api, Route $route, ReflectionMethod $reflectionMethod): void
    {
        $attributes = $reflectionMethod->getAttributes(Parameter::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attributes === []) {
            return;
        }
        $parameters = array_map(
            static fn(ReflectionAttribute $attribute): Parameter => $attribute->newInstance(),
            $attributes,
        );
        $compiler = new OpenApi30Compiler();
        if (!$compiler->supports($api->openapi)) {
            throw new LogicException('Spec route parameters require the configured OpenAPI 3.0 export format.');
        }
        $specification = new Specification();
        $specification->openapi->version = $api->openapi;
        $specification->add(new SpecOperation(
            path: '/',
            method: 'get',
            parameters: $parameters,
        ));
        $document = $compiler->compile($specification);
        $compiledParameters = $document['paths']['/']['get']['parameters'];
        $serializer = new Serializer();
        foreach ($this->getOperations($api, $route) as $operation) {
            foreach ($compiledParameters as $parameter) {
                $context = new Context(
                    ['generated' => true, 'nested' => $operation],
                    $operation->_context,
                );
                $export = $serializer->deserialize(
                    json_encode($parameter, JSON_THROW_ON_ERROR),
                    ExportParameter::class,
                    $context,
                );
                assert($export instanceof ExportParameter);
                self::mergeParameter($operation, $export);
            }
        }
    }

    private static function mergeParameter(Operation $operation, ExportParameter $parameter): void
    {
        $parameters = Undefined::isDefault($operation->parameters) ? [] : $operation->parameters;
        foreach ($parameters as $index => $existing) {
            if (self::identity($existing) === self::identity($parameter)) {
                $parameters[$index] = $parameter;
                $operation->parameters = $parameters;
                return;
            }
        }
        $parameters[] = $parameter;
        $operation->parameters = $parameters;
    }

    private static function identity(ExportParameter $parameter): string
    {
        return Undefined::isDefault($parameter->ref)
            ? $parameter->name . '/' . $parameter->in
            : '$ref:' . $parameter->ref;
    }
}
