<?php

namespace Baander\RedisStack\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Entity
{
    public function __construct(public ?string $prefix = null) {} // Entity's key prefix
}