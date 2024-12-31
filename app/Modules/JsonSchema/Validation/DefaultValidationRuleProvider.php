<?php

namespace App\Modules\JsonSchema\Validation;

use Illuminate\Database\Eloquent\Model;

class DefaultValidationRuleProvider implements ValidationRuleProviderInterface
{
    public function getRules(Model $model): array
    {
        return method_exists($model, 'jsonSchemaRules') ? $model->jsonSchemaRules() : [];
    }
}