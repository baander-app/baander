<?php

namespace App\Modules\OpenApi;

use App\Extensions\Pagination\JsonPaginator;
use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\{Response, Schema};
use Dedoc\Scramble\Support\Generator\Types\{ArrayType, IntegerType, ObjectType as OpenApiObjectType};
use Dedoc\Scramble\Support\Type\{Generic, ObjectType, Type};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

class JsonPaginatorExtension extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type)
    {
        return $type instanceof Generic
            && $type->name === JsonPaginator::class
            && count($type->templateTypes) === 1
            && $type->templateTypes[0] instanceof ObjectType;
    }

    public function toResponse(Type $type)
    {
        $collectingClassType = $type->templateTypes[0];

        if (!$collectingClassType->isInstanceOf(JsonResource::class) && !$collectingClassType->isInstanceOf(Model::class)) {
            return null;
        }

        if (!($collectingType = $this->openApiTransformer->transform($collectingClassType))) {
            return null;
        }

        $type = new OpenApiObjectType;

        $type->addProperty('data', (new ArrayType())->setItems($collectingType))
            ->addProperty('total', (new IntegerType)->setDescription('Total number of items being paginated.'))
            ->addProperty('count', (new IntegerType)->setDescription('The number of items for the current page'))
            ->addProperty('limit', (new IntegerType)->setDescription('The number of items per page'))
            ->addProperty('currentPage', (new IntegerType)->setDescription('The number of current page'))
            ->addProperty('nextPage', (new IntegerType)->setDescription('The number of next page'))
            ->addProperty('lastPage', (new IntegerType)->setDescription('The number of last page'));

        $type->setRequired([
            'data',
            'total',
            'count',
            'limit',
            'currentPage',
            'nextPage',
            'lastPage',
        ]);

        return Response::make(200)
            ->description('Json paginated set of `' . $this->components->uniqueSchemaName($collectingClassType->name) . '`')
            ->setContent('application/json', Schema::fromType($type));
    }
}