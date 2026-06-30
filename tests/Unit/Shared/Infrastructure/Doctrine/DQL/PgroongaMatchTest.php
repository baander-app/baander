<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine\DQL;

use App\Shared\Infrastructure\Doctrine\DQL\PgroongaMatch;
use Doctrine\ORM\Query\Lexer;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PgroongaMatchTest extends TestCase
{
    public function testSqlOutputContainsAtTildeOperator(): void
    {
        $function = new PgroongaMatch('pgroonga_match');

        $column = $this->createMockSqlWalkerDispatchable('s.title');
        $query = $this->createMockSqlWalkerDispatchable(':query');

        $reflection = new \ReflectionClass($function);

        $columnProp = $reflection->getProperty('column');
        $columnProp->setValue($function, $column);

        $queryProp = $reflection->getProperty('query');
        $queryProp->setValue($function, $query);

        $sqlWalker = $this->createMock(SqlWalker::class);
        $sql = $function->getSql($sqlWalker);

        $this->assertStringContainsString('&@~', $sql);
        $this->assertSame('s.title &@~ :query', $sql);
    }

    private function createMockSqlWalkerDispatchable(string $output): MockObject
    {
        $mock = $this->createMock(\Doctrine\ORM\Query\AST\Node::class);
        $mock->method('dispatch')->willReturn($output);

        return $mock;
    }
}
