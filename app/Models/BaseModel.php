<?php

namespace App\Models;

use App\Models\Concerns\IsBaseModel;
use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    use IsBaseModel;

    protected $dateFormat = 'Y-m-d H:i:sO';
}
