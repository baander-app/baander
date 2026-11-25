<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Fixtures\NestedCallPipeOperator;

use Attribute;

// PHP rejects calls in attribute arguments when it compiles this file; the parser accepts them.

#[Attribute]
final class Marker
{
    public function __construct(public string $value)
    {
    }
}

final class AttributeArguments
{
    #[Marker(strtoupper(trim(strtolower('attribute'))))]
    public function attributeOwnsTheTopArgumentList(): void
    {
    }
}
