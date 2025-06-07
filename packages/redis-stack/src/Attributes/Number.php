<?php

namespace Baander\RedisStack\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Number
{
    public function __construct(public bool $sortable = false) {} // Optional number sorting
}