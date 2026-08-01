<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine\DQL;

use App\Shared\Infrastructure\Doctrine\DQL\TrigramSimilarity;
use Doctrine\ORM\Query\SqlWalker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TrigramSimilarityTest extends TestCase
{
    public function testSqlOutputWrapsWithSimilarityFunction(): void
    {
        $function = new TrigramSimilarity('trigram_similarity');

        $left = $this->createMockSqlWalkerDispatchable('s.title');
        $right = $this->createMockSqlWalkerDispatchable(':query');

        $reflection = new \ReflectionClass($function);

        $leftProp = $reflection->getProperty('left');
        $leftProp->setValue($function, $left);

        $rightProp = $reflection->getProperty('right');
        $rightProp->setValue($function, $right);

        $sqlWalker = $this->createMock(SqlWalker::class);
        $sql = $function->getSql($sqlWalker);

        $this->assertStringContainsString('similarity(', $sql);
        $this->assertSame('similarity(s.title, :query)', $sql);
    }

    private function createMockSqlWalkerDispatchable(string $output): MockObject
    {
        $mock = $this->createMock(\Doctrine\ORM\Query\AST\Node::class);
        $mock->method('dispatch')->willReturn($output);

        return $mock;
    }
}
