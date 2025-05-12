<?php

namespace Baander\RedisStack\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Entity
{
    public function __construct(public ?string $prefix = null) {} // Entity's key prefix
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Id
{
    // Indicates primary unique key
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Text
{
    public function __construct(public bool $sortable = false) {} // Optional text sorting
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Number
{
    public function __construct(public bool $sortable = false) {} // Optional number sorting
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Boolean
{
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Date
{
    public function __construct(public bool $sortable = false) {} // Optional date sorting
}