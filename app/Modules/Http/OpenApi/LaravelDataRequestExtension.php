<?php

namespace App\Modules\Http\OpenApi;

use App\Modules\Http\OpenApi\Concerns\CastsDtoToSchema;
use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\{Operation, RequestBodyObject};
use Dedoc\Scramble\Support\RouteInfo;
use ReflectionNamedType;
use Spatie\LaravelData\Data;

class LaravelDataRequestExtension extends OperationExtension
{
    use CastsDtoToSchema;

    /**
     * @return void
     */
    public function handle(Operation $operation, RouteInfo $routeInfo)
    {
        if (($method = $routeInfo->reflectionMethod()) === null) {
            return;
        }

        foreach ($method->getParameters() as $parameter) {
            /* Doing this check inline since PHPStan complains otherwise */
            if (
                !(($type = $parameter->getType()) instanceof ReflectionNamedType) ||
                !is_a($type->getName(), Data::class, true)
            ) {
                continue;
            }

            $this->handleRequestData($operation, $type);
        }
    }

    protected function handleRequestData(Operation $operation, ReflectionNamedType $type): void
    {
        $name = $type->getName();
        $supportsBody = in_array($operation->method, ['post', 'put', 'patch', 'delete'], true);

        if (!$supportsBody) {
            $operation->addParameters($this->parametersFromDto($name));

            return;
        }

        $operation->addRequestBodyObject(
            RequestBodyObject::make()->setContent('application/json', $this->schemaFromDto($name)),
        );
    }
}