<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;

class Recommendation extends BaseModel
{
    protected $fillable = [
        'source_type',
        'source_id',
        'target_type',
        'target_id',
        'user_id',
        'name',
        'score', // raw score means that multiple items can have identical scores
        'position', // explicit rank ensures a clear, unambiguous sorting order
    ];

    /**
     * Get the source model that the recommendation belongs to.
     *
     * @return MorphTo
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the target model for the recommendation.
     *
     * @return MorphTo
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
