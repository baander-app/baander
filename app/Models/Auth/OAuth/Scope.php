<?php

declare(strict_types=1);

namespace App\Models\Auth\OAuth;

use App\Models\BaseModel;

class Scope extends BaseModel
{
    protected $table = 'oauth_scopes';

    protected $fillable = [
        'id',
        'description',
    ];

    public $incrementing = false;
    protected $keyType = 'string';
}
