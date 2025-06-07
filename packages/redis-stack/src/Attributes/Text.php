<?php

namespace Baander\RedisStack\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Text
{
    public function __construct(public bool $sortable = false) {} // Optional text sorting
}