<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TranscodingSession extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'options',
    ];

    protected $casts = [
        'options' => 'array',
    ];
}
