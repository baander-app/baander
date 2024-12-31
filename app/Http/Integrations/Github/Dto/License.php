<?php

namespace App\Http\Integrations\Github\Dto;

use Spatie\LaravelData\Data;

class License extends Data
{
    public function __construct(
        public ?string $key,
        public ?string $name,
        public ?string $spdx_id,
        public ?string $url,
        public ?string $node_id
    ) {}
}