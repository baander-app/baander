<?php

namespace App\Packages\JsonSchema;

use App\Packages\JsonSchema\Validation\ValidationRuleProviderInterface;
use Illuminate\Database\Eloquent\Model;
use App\Repositories\Cache\CacheRepositoryInterface;

class ModelSchema
{
    private CacheRepositoryInterface $cacheRepository;
    private ValidationRuleProviderInterface $validationRuleProvider;
    private int $cacheDuration = 0;

    public function __construct(
        CacheRepositoryInterface        $cacheRepository,
        ValidationRuleProviderInterface $validationRuleProvider,
    )
    {
        $this->cacheRepository = $cacheRepository;
        $this->validationRuleProvider = $validationRuleProvider;
    }

    /**
     * Build JSON Schema for the given model.
     *
     * @param Model $model
     * @return array
     */
    public function buildSchemaFor(Model $model): array
    {
        $schemaBuilder = new SchemaBuilder($model, $this->cacheRepository, $this->validationRuleProvider, $this->cacheDuration);

        return $schemaBuilder->build();
    }
}

