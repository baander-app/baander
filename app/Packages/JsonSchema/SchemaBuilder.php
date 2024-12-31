<?php

namespace App\Packages\JsonSchema;

use App\Packages\JsonSchema\Eloquent\JsonSchemaRepresentable;
use App\Packages\JsonSchema\Validation\ValidationRuleProviderInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany, HasMany, HasOne, Relation};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use App\Repositories\Cache\CacheRepositoryInterface;
use JetBrains\PhpStorm\ArrayShape;
use ReflectionMethod;

class SchemaBuilder
{
    private Model $model;
    private CacheRepositoryInterface $cacheRepository;
    private ValidationRuleProviderInterface $validationRuleProvider;
    private int $cacheDuration;

    public function __construct(
        Model                           $model,
        CacheRepositoryInterface        $cacheRepository,
        ValidationRuleProviderInterface $validationRuleProvider,
        int                             $cacheDuration,
    )
    {
        $this->model = $model;
        $this->cacheRepository = $cacheRepository;
        $this->validationRuleProvider = $validationRuleProvider;
        $this->cacheDuration = $cacheDuration;
    }

    public function build(): array
    {
        $cacheKey = $this->getCacheKey();
        $cacheTags = $this->getCacheTags();

        if ($this->cacheDuration > 0 && $this->cacheRepository->has($cacheKey, $cacheTags)) {
            return $this->cacheRepository->get($cacheKey, $cacheTags);
        }

        $schema = $this->generateSchema();
        $this->cacheRepository->put($cacheKey, $schema, $this->cacheDuration, $cacheTags);

        return $schema;
    }

    private function generateSchema(): array
    {
        $fillable = $this->model->getFillable();
        $properties = [];
        $required = [];

        foreach ($fillable as $field) {
            $options = $this->getUserFieldOptions($field);

            if (in_array('required', $options) && Arr::get($options, 'required') === true) {
                $required[] = $field;
                unset($options['required']);
            }

            $properties[$field] = $this->getFieldSchema($field, $options);
        }

        foreach ($this->getRelations() as $relation) {
            $properties[$relation] = $this->getRelationSchema($relation);
        }

        return [
            '$schema'    => 'http://json-schema.org/draft-07/schema#',
            'type'       => 'object',
            'properties' => $properties,
            'required'   => $required,
        ];
    }

    private function getFieldSchema(string $field, array $options): array
    {
        $type = $this->getFieldType($field);
        $schema = ['type' => $type];
        $schema += $options;



        return $schema;
    }

    private function getFieldType(string $field): string
    {
        $columnType = Schema::getColumnType($this->model->getTable(), $field);

        return $this->mapDatabaseTypeToJsonSchemaType($columnType);
    }

    private function getUserFieldOptions(string $field): array
    {
        if (!$this->model instanceof JsonSchemaRepresentable) {
            return [];
        }

        $fields = $this->model->getJsonSchemaFieldOptions();

        return $fields[$field] ?? [];
    }

    private function mapDatabaseTypeToJsonSchemaType(string $columnType): string
    {
        return match ($columnType) {
            'string', 'text' => 'string',
            'integer', 'bigint', 'smallint' => 'integer',
            'float', 'double', 'decimal' => 'number',
            'boolean' => 'boolean',
            'date', 'datetime', 'timestamp' => 'string', // Consider using 'format'
            default => 'string',
        };
    }

    private function getRelations(): array
    {
        $relations = [];

        foreach (get_class_methods($this->model) as $method) {
            if ($this->isRelationMethod($method)) {
                $relations[] = $method;
            }
        }

        return $relations;
    }

    private function getRelationSchema(string $relation): array
    {
        $relationMethod = $this->model->$relation();

        if ($relationMethod instanceof HasOne || $relationMethod instanceof BelongsTo) {
            return (new static($relationMethod->getModel(), $this->cacheRepository, $this->validationRuleProvider, $this->cacheDuration))->build();
        } else if ($relationMethod instanceof HasMany || $relationMethod instanceof BelongsToMany) {
            return [
                'type'  => 'array',
                'items' => (new static($relationMethod->getModel(), $this->cacheRepository, $this->validationRuleProvider, $this->cacheDuration))->build(),
            ];
        }

        return [];
    }

    private function isRelationMethod(string $method): bool
    {
        $reflection = new ReflectionMethod($this->model, $method);
        $returnType = $reflection->getReturnType();
        return $returnType && is_subclass_of((string)$returnType, Relation::class);
    }

    private function getCacheKey(): string
    {
        return 'json_schema_' . class_basename($this->model) . '_' . $this->model->getTable();
    }

    private function getCacheTags(): array
    {
        return ['json_schema', 'model_' . class_basename($this->model)];
    }
}