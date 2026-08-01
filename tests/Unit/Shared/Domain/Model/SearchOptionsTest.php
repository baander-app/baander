<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Model;

use App\Shared\Domain\Model\SearchOptions;
use PHPUnit\Framework\TestCase;

final class SearchOptionsTest extends TestCase
{
    public function testCreateWithDefaults(): void
    {
        $options = SearchOptions::create('test query', 10, 0);

        $this->assertSame('test query', $options->getQuery());
        $this->assertSame(10, $options->getLimit());
        $this->assertSame(0, $options->getOffset());
        $this->assertSame([], $options->getFields());
        $this->assertSame(0.0, $options->getMinScore());
        $this->assertSame([], $options->getFilters());
        $this->assertTrue($options->hasQuery());
    }

    public function testCreateWithAllFields(): void
    {
        $options = SearchOptions::create('query', 50, 10)
            ->withFields(['title', 'name'])
            ->withMinScore(0.5)
            ->withFilters([
                ['field' => 'genres', 'operator' => 'IN', 'value' => ['rock']],
            ]);

        $this->assertSame('query', $options->getQuery());
        $this->assertSame(50, $options->getLimit());
        $this->assertSame(10, $options->getOffset());
        $this->assertSame(['title', 'name'], $options->getFields());
        $this->assertSame(0.5, $options->getMinScore());
        $this->assertCount(1, $options->getFilters());
    }

    public function testHasQueryReturnsFalseForEmptyString(): void
    {
        $options = SearchOptions::create('');

        $this->assertFalse($options->hasQuery());
    }

    public function testHasQueryReturnsFalseForWhitespace(): void
    {
        $options = SearchOptions::create('   ');

        $this->assertFalse($options->hasQuery());
    }

    public function testWithMethodsReturnNewInstances(): void
    {
        $original = SearchOptions::create('test', 10);
        $modified = $original->withFields(['title']);

        $this->assertNotSame($original, $modified);
        $this->assertSame([], $original->getFields());
        $this->assertSame(['title'], $modified->getFields());
    }
}
