<?php

namespace App\Modules\JsonSchema\Validation;

use Illuminate\Database\Eloquent\Model;

interface ValidationRuleProviderInterface
{
    public function getRules(Model $model): array;
}