<?php

namespace Tests\Unit\Primitives;

use App\Primitives\Traits\EnumExtensions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

enum StringBackedEnum: string
{
    use EnumExtensions;

    case Alpha = 'alpha';
    case Beta = 'beta';
    case Gamma = 'gamma';
}

enum IntBackedEnum: int
{
    use EnumExtensions;

    case One = 1;
    case Two = 2;
    case Three = 3;
}

enum PlainEnum
{
    use EnumExtensions;

    case First;
    case Second;
}

enum SingleCaseEnum: string
{
    use EnumExtensions;

    case Only = 'only';
}

class EnumExtensionsTest extends TestCase
{
    #[Test]
    public function it_returns_all_names(): void
    {
        $this->assertSame(
            ['Alpha', 'Beta', 'Gamma'],
            StringBackedEnum::names()
        );
    }

    #[Test]
    public function it_returns_all_values(): void
    {
        $this->assertSame(
            ['alpha', 'beta', 'gamma'],
            StringBackedEnum::values()
        );
    }

    #[Test]
    public function it_returns_value_to_name_array(): void
    {
        $this->assertSame(
            ['alpha' => 'Alpha', 'beta' => 'Beta', 'gamma' => 'Gamma'],
            StringBackedEnum::array()
        );
    }

    #[Test]
    public function it_converts_snake_to_camel(): void
    {
        $this->assertSame('helloWorld', StringBackedEnum::toCamelCase('hello_world'));
        $this->assertSame('fooBarBaz', StringBackedEnum::toCamelCase('foo_bar_baz'));
    }

    #[Test]
    public function it_works_with_int_backed_enums(): void
    {
        $this->assertSame(['One', 'Two', 'Three'], IntBackedEnum::names());
        $this->assertSame([1, 2, 3], IntBackedEnum::values());
    }

    #[Test]
    public function it_works_with_single_case_enum(): void
    {
        $this->assertSame(['Only'], SingleCaseEnum::names());
        $this->assertSame(['only'], SingleCaseEnum::values());
    }

    #[Test]
    public function it_works_with_plain_enums(): void
    {
        $this->assertSame(['First', 'Second'], PlainEnum::names());
        // Plain enums have no 'value' property, so array_column returns empty
        $this->assertSame([], PlainEnum::values());
    }
}
