<?php

require __DIR__ . '/../../vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

class RefactorVisitor extends NodeVisitorAbstract
{
    public function enterNode(Node $node) {
        if ($node instanceof Node\Stmt\Class_) {
            $constructor = null;
            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name->toString() === '__construct') {
                    $constructor = $stmt;
                    break;
                }
            }

            if ($constructor) {
                foreach ($constructor->params as $param) {
                    $paramName = $param->var->name;
                    foreach ($node->stmts as $stmt) {
                        if ($stmt instanceof Node\Stmt\Property && $stmt->props[0]->name->name !== $paramName) {
                            $constructor->stmts[] = new Node\Stmt\If_(
                                new Node\Expr\FuncCall(new Node\Name('isset'), [new Node\Arg(new Node\Expr\ArrayDimFetch(new Node\Expr\Variable($paramName), new Node\Scalar\String_($stmt->props[0]->name->name)))]),
                                [
                                    'stmts' => [
                                        new Node\Stmt\Expression(new Node\Expr\Assign(new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $stmt->props[0]->name->name), new Node\Expr\ArrayDimFetch(new Node\Expr\Variable($paramName), new Node\Scalar\String_($stmt->props[0]->name->name))))
                                    ]
                                ]
                            );
                        }
                    }
                }
            } else {
                $properties = array_filter($node->stmts, function($stmt) {
                    return $stmt instanceof Node\Stmt\Property;
                });

                $paramName = 'data';
                $stmts = [];
                foreach ($properties as $property) {
                    $stmts[] = new Node\Stmt\If_(
                        new Node\Expr\FuncCall(new Node\Name('isset'), [new Node\Arg(new Node\Expr\ArrayDimFetch(new Node\Expr\Variable($paramName), new Node\Scalar\String_($property->props[0]->name->name)))]),
                        [
                            'stmts' => [
                                new Node\Stmt\Expression(new Node\Expr\Assign(new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $property->props[0]->name->name), new Node\Expr\ArrayDimFetch(new Node\Expr\Variable($paramName), new Node\Scalar\String_($property->props[0]->name->name))))
                            ]
                        ]
                    );
                }

                $constructor = new Node\Stmt\ClassMethod(
                    '__construct',
                    ['flags' => Modifiers::PUBLIC],
                    [
                        'params' => [
                            new Node\Param(
                                new Node\Expr\Variable($paramName),
                                null,
                                new Node\Name('array')
                            )
                        ],
                        'stmts' => $stmts
                    ]
                );
                $node->stmts[] = $constructor;
            }

            return $node;
        }
    }
}

class PhpFileRefactor
{
    public function refactorFile(string $filePath) {
        $code = file_get_contents($filePath);

        $parser = (new ParserFactory)->createForHostVersion();
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new RefactorVisitor());
        $prettyPrinter = new Standard;

        try {
            $ast = $parser->parse($code);
            $ast = $traverser->traverse($ast);
            $refactoredCode = $prettyPrinter->prettyPrintFile($ast);
            file_put_contents($filePath, $refactoredCode);
            echo "Refactored: $filePath" . PHP_EOL;
        } catch (Error $e) {
            echo "Parse error: {$e->getMessage()}" . PHP_EOL;
        }
    }
}

$refactor = new PhpFileRefactor();
$directories = [
    __DIR__ . '/src/Value/SearchResult',
    __DIR__ . '/src/Value'
];

$modelFiles = [];

foreach ($directories as $directory) {
    $modelFiles = array_merge($modelFiles, scanDirectory($directory));
}

foreach ($modelFiles as $filePath) {
    $refactor->refactorFile($filePath);
}

/**
 * Scans the directory recursively.
 *
 * @param string $dir The directory to scan
 *
 * @return array
 */
function scanDirectory(string $dir): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    foreach ($iterator as $item) {
        if ($item->isFile() && $item->getExtension() === 'php') {
            $files[] = $item->getPathname();
        }
    }

    return $files;
}