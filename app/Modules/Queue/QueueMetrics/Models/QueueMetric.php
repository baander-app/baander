<?php

namespace App\Modules\Queue\QueueMetrics\Models;

use Spatie\LaravelData\Data;

class QueueMetric extends Data
{
    public function __construct(
        public string  $title,
        public float   $value,
        public ?int    $previousValue = null,
        public string  $format = '%d',
        public ?string $formattedValue = null,
        public ?string $formattedPreviousValue = null,
    )
    {
        $this->formattedValue = sprintf($format, $this->value);
        if ($this->previousValue) {
            $this->formattedPreviousValue = sprintf($format, $this->previousValue);
        }
    }


}