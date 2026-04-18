<?php

namespace App\Models;

use App\Models\Concerns\IsBaseModel;
use Illuminate\Database\Eloquent\Model;
use App\Primitives\Text;

abstract class BaseModel extends Model
{
    use IsBaseModel;

    protected $dateFormat = 'Y-m-d H:i:sO';

    public function update(array $attributes = [], array $options = [])
    {
        $snakeCasedAttributes = [];
        foreach ($attributes as $key => $value) {
            $snakeCasedAttributes[Text::snake($key)->value()] = $value;
        }

        return parent::update($snakeCasedAttributes, $options);
    }

}
