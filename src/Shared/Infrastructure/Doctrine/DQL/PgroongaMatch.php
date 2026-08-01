<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

final class PgroongaMatch extends FunctionNode
{
    private mixed $column = null;
    private mixed $query = null;

    public function __construct(string $name)
    {
        parent::__construct($name);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return $this->column->dispatch($sqlWalker) . ' &@~ ' . $this->query->dispatch($sqlWalker);
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->column = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->query = $parser->ArithmeticPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
