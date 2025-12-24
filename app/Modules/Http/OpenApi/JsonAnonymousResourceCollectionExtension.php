<?php

namespace App\Modules\Http\OpenApi;

use App\Modules\Http\Pagination\JsonPaginator;
use App\Modules\Http\Resources\Json\JsonAnonymousResourceCollection;
use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\{Response, Schema};
use Dedoc\Scramble\Support\Generator\Types\{ArrayType, IntegerType, ObjectType as OpenApiObjectType};
use Dedoc\Scramble\Support\Type\{Generic, ObjectType, Type};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

class JsonAnonymousResourceCollectionExtension extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type): bool
    {
        // Handle JsonAnonymousResourceCollection<JsonPaginator<Resource>>
        if (!$type instanceof Generic) {
            return false;
        }

        // Must be JsonAnonymousResourceCollection with exactly 1 template type
        if ($type->name !== JsonAnonymousResourceCollection::class || count($type->templateTypes) !== 1) {
            return false;
        }

        $innerType = $type->templateTypes[0];

        // Inner type must be Generic and must be JsonPaginator
        if (!$innerType instanceof Generic || $innerType->name !== JsonPaginator::class) {
            return false;
        }

        // JsonPaginator must have exactly 1 template type that's an ObjectType
        return count($innerType->templateTypes) === 1
            && $innerType->templateTypes[0] instanceof ObjectType;
    }

    public function toResponse(Type $type)
    {
        /** @var Generic $type */
        /** @var Generic $innerPaginatorType */
        $innerPaginatorType = $type->templateTypes[0];
        $collectingClassType = $innerPaginatorType->templateTypes[0];

        if (!$collectingClassType->isInstanceOf(JsonResource::class) && !$collectingClassType->isInstanceOf(Model::class)) {
            return null;
        }

        if (!($collectingType = $this->openApiTransformer->transform($collectingClassType))) {
            return null;
        }

        $responseType = new OpenApiObjectType;

        $responseType->addProperty('data', (new ArrayType)->setItems($collectingType))
            ->addProperty('total', (new IntegerType)->setDescription('Total number of items being paginated.'))
            ->addProperty('count', (new IntegerType)->setDescription('The number of items for the current page'))
            ->addProperty('limit', (new IntegerType)->setDescription('The number of items per page'))
            ->addProperty('currentPage', (new IntegerType)->setDescription('The number of current page'))
            ->addProperty('nextPage', (new IntegerType)->setDescription('The number of next page'))
            ->addProperty('lastPage', (new IntegerType)->setDescription('The number of last page'));

        $responseType->setRequired([
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
            ->setContent('application/json', Schema::fromType($responseType));
    }
}
