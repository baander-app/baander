<?php

namespace App\Models;

use App\Models\Concerns\IsBaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Overtrue\LaravelVersionable\Version as BaseVersion;

class Version extends BaseVersion
{
    use SoftDeletes, IsBaseModel;

    protected $dateFormat = 'Y-m-d H:i:sO';

    protected $table = 'versions';

    protected $guarded = [];

    /**
     * @var array
     */
    protected $casts = [
        'contents' => 'json',
    ];

    protected $with = [
        'versionable',
    ];

    public function getIncrementing()
    {
        return true;
    }

    public function getKeyType()
    {
        return $this->keyType;
    }
}
