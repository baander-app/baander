<?php

declare(strict_types=1);

namespace Baander\Core\Tests;

use Baander\Core\Configuration\ConfigPath;
use Baander\Core\Tests\Fixtures\ConfigField;
use Baander\Core\Tests\Fixtures\NumericConfigField;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigPathTest extends TestCase
{
    public function testRootAndBranchesRemainIndependent(): void
    {
        $configuration = ['worker' => ['capacity' => 3, 'reserve' => 1]];
        $root = ConfigPath::root();
        $worker = $root->key('worker');
        $capacity = $worker->key('capacity');
        $reserve = $worker->key('reserve');

        self::assertSame($configuration, $root->value($configuration));
        self::assertSame($configuration['worker'], $worker->value($configuration));
        self::assertSame(3, $capacity->integer($configuration));
        self::assertSame(1, $reserve->integer($configuration));
    }

    public function testNestedIndexesAndLiteralDottedKeys(): void
    {
        $configuration = ['workers' => [['pool.size' => 4]]];
        $path = ConfigPath::root()->key('workers')->index(0)->key('pool.size');

        self::assertSame(4, $path->integer($configuration));
    }

    public function testStringBackedEnumUsesItsValue(): void
    {
        self::assertSame(7, ConfigPath::root()->key(ConfigField::Capacity)->integer(['capacity' => 7]));
    }

    public function testIntegerBackedEnumIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('string-backed enums');
        ConfigPath::root()->key(NumericConfigField::Capacity);
    }

    public function testNegativeIndexIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nonnegative');
        ConfigPath::root()->index(-1);
    }

    #[DataProvider('presentValues')]
    public function testPresentValuesAreNotTreatedAsMissing(mixed $value): void
    {
        self::assertSame($value, ConfigPath::root()->key('value')->value(['value' => $value]));
    }

    /** @return iterable<string,array{mixed}> */
    public static function presentValues(): iterable
    {
        yield 'null' => [null];
        yield 'zero' => [0];
        yield 'false' => [false];
        yield 'empty string' => [''];
        yield 'empty array' => [[]];
    }

    /** @param array<array-key,mixed> $configuration */
    #[DataProvider('invalidTraversal')]
    public function testInvalidTraversalIdentifiesPathWithoutValues(array $configuration, string $message): void
    {
        try {
            ConfigPath::root()->key('worker')->key('capacity')->value($configuration);
            self::fail('Invalid configuration traversal was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertSame($message, $error->getMessage());
            self::assertStringNotContainsString('private-configuration-value', $error->getMessage());
        }
    }

    /** @return iterable<string,array{array<array-key,mixed>,string}> */
    public static function invalidTraversal(): iterable
    {
        yield 'missing intermediate' => [[], "Missing configuration key at $['worker']."];
        yield 'missing leaf' => [['worker' => []], "Missing configuration key at $['worker']['capacity']."];
        yield 'scalar intermediate' => [['worker' => 'private-configuration-value'], "Configuration parent of $['worker']['capacity'] must be an array; got string."];
        yield 'null intermediate' => [['worker' => null], "Configuration parent of $['worker']['capacity'] must be an array; got null."];
    }

    public function testMissingIndexNamesItsPosition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing configuration key at $['workers'][2].");
        ConfigPath::root()->key('workers')->index(2)->value(['workers' => []]);
    }

    public function testEmptyKeyIsLiteral(): void
    {
        self::assertSame(5, ConfigPath::root()->key('')->integer(['' => 5]));
    }

    public function testIntegerReadsRetainIntegerValues(): void
    {
        $path = ConfigPath::root()->key('capacity');
        foreach ([-3, 0, 7] as $value) {
            self::assertSame($value, $path->integer(['capacity' => $value]));
        }
    }

    #[DataProvider('nonIntegers')]
    public function testIntegerReadsRejectCoercion(mixed $value, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Configuration value at $['capacity'] must be an integer; got " . $type . '.');
        ConfigPath::root()->key('capacity')->integer(['capacity' => $value]);
    }

    /** @return iterable<string,array{mixed,string}> */
    public static function nonIntegers(): iterable
    {
        yield 'numeric string' => ['1', 'string'];
        yield 'float' => [1.0, 'float'];
        yield 'boolean' => [false, 'bool'];
        yield 'null' => [null, 'null'];
        yield 'array' => [[], 'array'];
        yield 'object' => [new \stdClass(), 'stdClass'];
    }
}
