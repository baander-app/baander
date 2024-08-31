<?php

namespace App\Packages\JsonSchema\Validation;

use Illuminate\Database\Eloquent\Model;

interface ValidationRuleProviderInterface
{
    public function getRules(Model $model): array;
}