<?php

namespace Baander\RedisStack\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Date
{
    public function __construct(public bool $sortable = false) {} // Optional date sorting
}