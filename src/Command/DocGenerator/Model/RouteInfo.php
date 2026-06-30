<?php

declare(strict_types=1);

namespace App\Command\DocGenerator\Model;

final readonly class RouteInfo
{
    /**
     * @param string $methods HTTP methods (GET, POST, etc.)
     * @param string $path Full route path (class prefix + method path)
     * @param string $name Route name
     * @param string $controllerFqcn Controller FQCN
     * @param string $methodName Controller method name
     * @param string $description Method docblock description
     */
    public function __construct(
        public string $methods,
        public string $path,
        public string $name,
        public string $controllerFqcn,
        public string $methodName,
        public string $description = '',
    ) {}
}
