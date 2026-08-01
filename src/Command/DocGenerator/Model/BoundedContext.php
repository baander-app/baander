<?php

declare(strict_types=1);

namespace App\Command\DocGenerator\Model;

final readonly class BoundedContext
{
    /**
     * @param string $name Context name (e.g., "Auth", "Catalog")
     * @param string $description Context description from docblock or fallback
     * @param list<ClassInfo> $classes All classes in this context
     * @param list<RouteInfo> $routes All routes in this context
     * @param list<ClassInfo> $aggregateRoots Aggregate roots
     * @param list<ClassInfo> $valueObjects Value objects
     * @param list<HandlerInfo> $handlers CQRS handlers
     * @param list<string> $layers Detected layer names
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $classes = [],
        public array $routes = [],
        public array $aggregateRoots = [],
        public array $valueObjects = [],
        public array $handlers = [],
        public array $layers = [],
    ) {}
}
